<?php

namespace Database\Seeders;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M04CustomOrder\T00OpenCustomOrder;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Audit\AuditAction;
use App\Catalog\CatalogStatus;
use App\Catalog\CatalogWriter;
use App\Enums\ActionStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Inventory\MovementKind;
use App\Models\Branch;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Catalog\CatalogVersion;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Finance\Payment;
use App\Models\Inventory\StockItem;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\Region;
use App\Models\RoleTemplate;
use App\Models\Task;
use App\Models\User;
use App\Workflow\FlowProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL/TESTING ONLY performance dataset (P9, Q-55, ADR-0027): per facility ≈ 5,000 customers, 20,000 orders and
 * 200,000 stock movements, spread over four branches, two years and every order state. Deterministic: a fixed PRNG
 * seed and an anchor day (today, or `PERF_SEED_ANCHOR=Y-m-d`) give the same rows on every run.
 *
 * Templates (staff, catalog, the flow and one real order) go through the domain Actions; the volume is bulk-inserted
 * with explicit ids, keeping the invariants the write paths enforce: every money journal sums to its expected value
 * (collection = payment amount, allocation = 0), no due is allocated above its amount, and every stock balance equals
 * the sum of its movements with `on_hand_after`/`reserved_after` never negative.
 *
 * Run on a migrated local database after `db:seed`: `./manage.sh artisan db:seed --class=PerformanceSeeder`.
 * The `scale` property shrinks the dataset for tests (1.0 = Q-55).
 */
class PerformanceSeeder extends Seeder
{
    public float $scale = 1.0;

    private const SEED = 55;

    private const CHUNK = 1000;

    private CarbonImmutable $anchor;

    private User $owner;

    /** @var list<int> */
    private array $branches = [];

    /** @var array<int, int> branch id → weight */
    private array $branchWeights = [];

    /** @var array<int, list<int>> branch id → sales/manager user ids */
    private array $sellers = [];

    /** @var list<int> */
    private array $executors = [];

    /** @var list<int> */
    private array $departments = [];

    /** @var list<array{id: int, price: int, version_id: int, snapshot: string}> */
    private array $variants = [];

    /** @var array<int, int> branch id → stock location id */
    private array $locations = [];

    /** @var array<string, int> */
    private array $ids = [];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('PerformanceSeeder runs in the local and testing environments only.');
        }
        if (Facility::current() === null) {
            throw new RuntimeException('Run the base seeders first (db:seed).');
        }
        if (Customer::query()->count() >= $this->n(5000)) {
            $this->command?->info('Performance dataset already present.');

            return;
        }

        mt_srand(self::SEED);
        $anchor = env('PERF_SEED_ANCHOR');
        $this->anchor = (is_string($anchor) && $anchor !== '' ? CarbonImmutable::parse($anchor) : CarbonImmutable::today())->startOfDay();
        $this->owner = User::query()->where('email', 'owner@demo.test')->first()
            ?? throw new RuntimeException('Run DemoSeeder first (local db:seed).');

        $this->organization();
        $this->catalog();
        $template = $this->templateOrder();
        $this->stockLocations();
        $this->primeIds();
        // The store sells from the first branch (its warehouse holds the stock the catalogue shows).
        DB::table('store_settings')->updateOrInsert(['id' => 1], ['enabled' => true, 'branch_id' => $this->branches[0], 'updated_at' => now()]);

        // One transaction for the volume: a failed run leaves nothing half-written behind.
        DB::transaction(function () use ($template) {
            $this->orders($this->customers(), $template);
            $this->stock();
        });
    }

    private function n(int $q55): int
    {
        return max(1, (int) round($q55 * $this->scale));
    }

    private function pick(array $list): mixed
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    /**
     * @param  array<array-key, int>  $weights
     */
    private function weighted(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    private function at(int $daysAgo, int $hour = 9): string
    {
        return $this->anchor->subDays($daysAgo)->setTime($hour, mt_rand(0, 59))->toDateTimeString();
    }

    /**
     * Four branches (two from DemoSeeder), three departments and ~26 extra staff through the membership Action.
     */
    private function organization(): void
    {
        $region = Region::query()->firstOrFail();
        foreach ([['JED', 'فرع جدة', 'Jeddah branch'], ['DMM', 'فرع الدمام', 'Dammam branch']] as [$code, $ar, $en]) {
            Branch::query()->firstOrCreate(['code' => $code], ['region_id' => $region->id, 'name_ar' => $ar, 'name_en' => $en]);
        }
        $this->branches = Branch::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->branchWeights = array_combine($this->branches, array_slice([50, 20, 18, 12], 0, count($this->branches)));

        $install = Department::query()->firstOrCreate(['name_en' => 'Installation'], ['name_ar' => 'التركيب']);
        $install->branches()->syncWithoutDetaching($this->branches);
        Department::query()->where('name_en', 'Production')->firstOrFail()->branches()->syncWithoutDetaching($this->branches);
        $this->departments = Department::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $create = app(T00CreateMembership::class);
        $templates = RoleTemplate::query()->pluck('id', 'key');
        foreach ($this->branches as $b => $branchId) {
            foreach (['sales' => 4, 'executor' => 3] as $role => $count) {
                for ($i = 1; $i <= $count; $i++) {
                    $email = "perf-{$role}-{$branchId}-{$i}@demo.test";
                    if (User::query()->where('email', $email)->exists()) {
                        continue;
                    }
                    $user = User::factory()->create(['name' => "{$role} {$branchId}-{$i}", 'email' => $email]);
                    $department = $role === 'executor' ? $this->departments[($b + $i) % count($this->departments)] : null;
                    $create->handle($user, $department, [$branchId], [['role_template_id' => (int) $templates[$role]]], ucfirst($role), $this->owner, TransitionRecorder::SOURCE_SEEDER);
                }
            }
        }

        foreach ($this->branches as $branchId) {
            $this->sellers[$branchId] = User::query()->where('email', 'like', "perf-sales-{$branchId}-%")->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $this->executors = User::query()->where('email', 'like', 'perf-executor-%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 40 published ready products × 3 variants through the catalog write path (versions and audit included).
     */
    private function catalog(): void
    {
        $writer = app(CatalogWriter::class);
        $names = [['كرسي', 'Chair'], ['طاولة', 'Table'], ['خزانة', 'Cabinet'], ['ستارة', 'Curtain'], ['رف', 'Shelf'], ['مرآة', 'Mirror'], ['سرير', 'Bed'], ['مكتب', 'Desk']];
        $sizes = [['صغير', 'Small'], ['وسط', 'Medium'], ['كبير', 'Large']];

        if (CatalogReadyVariant::query()->where('code', 'like', 'PERF-%')->doesntExist()) {
            for ($p = 1; $p <= 40; $p++) {
                [$ar, $en] = $names[$p % count($names)];
                $product = $writer->save($this->owner, Permission::P12, new CatalogReadyProduct, [
                    'name' => ['ar' => "{$ar} {$p}", 'en' => "{$en} {$p}"],
                    'description' => ['ar' => "وصف {$ar} {$p}", 'en' => "{$en} {$p} description"],
                ], AuditAction::CatalogReadyCreated, AuditAction::CatalogReadyUpdated);
                $writer->setStatus($this->owner, Permission::P12, $product, CatalogStatus::Published, AuditAction::CatalogReadyStatusChanged);

                foreach ($sizes as $s => [$sar, $sen]) {
                    $variant = $writer->save($this->owner, Permission::P12, new CatalogReadyVariant(['ready_product_id' => $product->id]), [
                        'code' => sprintf('PERF-%03d-%d', $p, $s + 1),
                        'name' => ['ar' => $sar, 'en' => $sen],
                        'price_minor' => (5000 + ($p * 1375) % 95000) * ($s + 1),
                        'is_default' => $s === 0,
                    ], AuditAction::CatalogVariantCreated, AuditAction::CatalogVariantUpdated);
                    $writer->setStatus($this->owner, Permission::P12, $variant, CatalogStatus::Published, AuditAction::CatalogVariantStatusChanged);
                }
            }
        }

        $type = (new CatalogReadyVariant)->getMorphClass();
        foreach (CatalogReadyVariant::query()->where('code', 'like', 'PERF-%')->orderBy('id')->get() as $variant) {
            $version = CatalogVersion::query()->where('versionable_type', $type)->where('versionable_id', $variant->id)
                ->where('version', $variant->versionNumber())->firstOrFail();
            $this->variants[] = [
                'id' => $variant->id,
                'price' => $variant->price_minor,
                'version_id' => $version->id,
                'snapshot' => (string) json_encode(['version' => $version->version, 'item' => $version->payload, 'options' => []], JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    /**
     * One real custom order through M-04-T00 (flow pinned, first stage started, its tasks created): its stage and
     * action rows are the shape every bulk custom order copies.
     *
     * @return array{order: Order, version_id: int, stages: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    private function templateOrder(): array
    {
        app(FlowProvisioner::class)->ensure();
        $order = Order::query()->where('need_summary', 'PERF-TEMPLATE')->first();
        if ($order === null) {
            $customer = Customer::query()->create([
                'kind' => 'person', 'name' => 'عميل قالب الأداء', 'mobile' => '+966500000001', 'country' => 'SA',
                'branch_id' => $this->branches[0], 'created_by' => $this->owner->id,
            ]);
            $order = DB::transaction(fn () => app(T00OpenCustomOrder::class)->handle($this->owner, [
                'customer_id' => $customer->id, 'branch_id' => $this->branches[0], 'need_summary' => 'PERF-TEMPLATE',
                'lines' => [['type' => 'custom_need', 'description' => 'مطبخ كامل', 'quantity' => '1']],
            ], source: TransitionRecorder::SOURCE_SEEDER));
        }

        return [
            'order' => $order,
            'version_id' => (int) DB::table('order_workflows')->where('order_id', $order->id)->value('flow_version_id'),
            'stages' => DB::table('order_stages')->where('order_id', $order->id)->orderBy('position')->get()->map(fn ($r) => (array) $r)->all(),
            'actions' => DB::table('order_actions')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function stockLocations(): void
    {
        foreach ($this->branches as $branchId) {
            $code = 'PERF-WH-'.$branchId;
            $id = DB::table('stock_locations')->where('code', $code)->value('id');
            if ($id === null) {
                $id = DB::table('stock_locations')->insertGetId([
                    'code' => $code, 'name' => json_encode(['ar' => 'مستودع '.$branchId, 'en' => 'Warehouse '.$branchId]),
                    'branch_id' => $branchId, 'status' => 'active', 'is_default' => false, 'lock_version' => 1,
                    'created_at' => $this->at(800), 'updated_at' => $this->at(800),
                ]);
            }
            $this->locations[$branchId] = (int) $id;
        }
    }

    private function primeIds(): void
    {
        foreach (['customers', 'customer_contacts', 'orders', 'order_lines', 'order_workflows', 'order_stages', 'order_actions',
            'tasks', 'appointments', 'appointment_members', 'state_transitions', 'order_finances', 'order_dues', 'payments',
            'receipts', 'money_journals', 'money_entries', 'payment_proofs', 'stock_items', 'stock_movements', 'stock_balances',
            'stock_reservations', 'interests'] as $table) {
            $this->ids[$table] = (int) DB::table($table)->max('id');
        }
    }

    private function nextId(string $table): int
    {
        return ++$this->ids[$table];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $buffer
     */
    private function flush(array &$buffer, bool $force = false): void
    {
        // Parents come first in the buffer, so once any table is full everything goes, in order (foreign keys).
        if (! $force && max(array_map('count', $buffer)) < self::CHUNK) {
            return;
        }
        foreach ($buffer as $table => $rows) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                // One insert needs one column list: rows that leave a column out get its null.
                $blank = array_fill_keys(array_keys(array_merge(...$chunk)), null);
                DB::table($table)->insert(array_map(fn (array $row) => $row + $blank, $chunk));
            }
            $buffer[$table] = [];
        }
    }

    /**
     * @return list<array{id: int, branch_id: int, kind: string, contact_id: int|null}>
     */
    private function customers(): array
    {
        $first = ['محمد', 'عبدالله', 'فهد', 'سارة', 'نورة', 'خالد', 'ريم', 'سلطان', 'هند', 'ماجد', 'Ahmed', 'Laila', 'Omar', 'Mona'];
        $last = ['العتيبي', 'القحطاني', 'الشهري', 'الغامدي', 'الدوسري', 'الحربي', 'Al-Saud', 'Hassan', 'Khan', 'المطيري'];
        $orgs = ['مؤسسة البناء', 'شركة الأفق', 'مجموعة النخبة', 'Vision Interiors', 'Gulf Homes', 'مطاعم الديرة'];
        $buffer = ['customers' => [], 'customer_contacts' => [], 'interests' => []];
        $out = [];
        $total = $this->n(5000);

        for ($i = 1; $i <= $total; $i++) {
            $id = $this->nextId('customers');
            $branch = (int) $this->weighted($this->branchWeights);
            $isOrg = mt_rand(1, 100) <= 15;
            $created = $this->at((int) (730 * (1 - sqrt(mt_rand(0, 1000) / 1000))), mt_rand(8, 20));
            $name = $isOrg ? $this->pick($orgs).' '.$i : $this->pick($first).' '.$this->pick($last);
            $buffer['customers'][] = [
                'id' => $id, 'kind' => $isOrg ? 'organization' : 'person', 'name' => $name,
                'mobile' => sprintf('+9665%08d', 10000000 + $i * 17), 'email' => mt_rand(1, 100) <= 45 ? "perf{$i}@example.test" : null,
                'country' => 'SA', 'branch_id' => $branch, 'assignee_id' => $this->pick($this->sellers[$branch]),
                'created_by' => $this->owner->id, 'portal_login_enabled' => mt_rand(1, 100) <= 30,
                'portal_session_version' => 1,
                'commercial_registration' => $isOrg ? sprintf('10%08d', $i) : null, 'vat_number' => $isOrg ? sprintf('3%013d3', $i) : null,
                'created_at' => $created, 'updated_at' => $created,
            ];
            $contact = null;
            if ($isOrg) {
                $contact = $this->nextId('customer_contacts');
                $buffer['customer_contacts'][] = [
                    'id' => $contact, 'customer_id' => $id, 'name' => $this->pick($first).' '.$this->pick($last),
                    'mobile' => sprintf('+9665%08d', 60000000 + $i), 'is_primary' => true, 'primary_of' => $id,
                    'created_at' => $created, 'updated_at' => $created,
                ];
            }
            // About one lead in three converts; the rest stay open or closed.
            if (mt_rand(1, 100) <= 40) {
                $status = $this->weighted(['new' => 20, 'following' => 25, 'converted' => 40, 'closed' => 15]);
                $interest = $this->nextId('interests');
                $buffer['interests'][] = [
                    'id' => $interest, 'reference' => sprintf('INT-P%06d', $interest), 'status' => $status, 'name' => $name,
                    'mobile' => sprintf('+9665%08d', 10000000 + $i * 17), 'need' => 'مطبخ وخزائن — '.$i, 'channel' => $this->pick(['phone', 'site', 'walk_in', 'whatsapp']),
                    'branch_id' => $branch, 'customer_id' => $status === 'converted' ? $id : null, 'assignee_id' => $this->pick($this->sellers[$branch]),
                    'created_by' => $this->owner->id, 'converted_customer_id' => $status === 'converted' ? $id : null,
                    'converted_at' => $status === 'converted' ? $created : null, 'closed_at' => $status === 'closed' ? $created : null,
                    'close_reason' => $status === 'closed' ? 'no_need' : null, 'created_at' => $created, 'updated_at' => $created,
                ];
            }
            $out[] = ['id' => $id, 'branch_id' => $branch, 'kind' => $isOrg ? 'organization' : 'person', 'contact_id' => $contact];
            $this->flush($buffer);
        }
        $this->flush($buffer, true);

        return $out;
    }

    /**
     * @param  list<array{id: int, branch_id: int, kind: string, contact_id: int|null}>  $customers
     * @param  array{order: Order, version_id: int, stages: list<array<string, mixed>>, actions: list<array<string, mixed>>}  $template
     */
    private function orders(array $customers, array $template): void
    {
        $tables = ['orders', 'order_lines', 'order_stages', 'order_actions', 'order_workflows', 'tasks', 'appointments',
            'appointment_members', 'state_transitions', 'order_finances', 'order_dues', 'payments', 'receipts',
            'money_journals', 'money_entries', 'payment_proofs'];
        $buffer = array_fill_keys($tables, []);
        $total = $this->n(20000);
        $numbers = [];
        $receipts = [];
        $orderMorph = (new Order)->getMorphClass();
        $stageCount = count($template['stages']);
        $taskTypes = collect($template['actions'])->pluck('type')->unique()->values()->all();
        $needs = ['مطبخ كامل', 'خزائن غرفة نوم', 'ستائر صالة', 'غرفة ملابس', 'Kitchen cabinets', 'Office furniture'];

        // Older orders are rarer: day offsets skewed towards the anchor, and the order ids follow their dates.
        $days = [];
        for ($i = 0; $i < $total; $i++) {
            $days[] = (int) (730 * (mt_rand(0, 1000) / 1000) ** 2);
        }
        rsort($days);

        foreach ($days as $i => $daysAgo) {
            $customer = $customers[(int) (count($customers) * (mt_rand(0, 1000) / 1001) ** 1.6)];
            $branch = mt_rand(1, 100) <= 90 ? $customer['branch_id'] : (int) $this->weighted($this->branchWeights);
            $custom = mt_rand(1, 100) <= 65;
            $created = $this->at($daysAgo, mt_rand(8, 21));
            $orderYear = (int) substr($created, 0, 4);
            $numbers[$orderYear] = ($numbers[$orderYear] ?? $this->lastNumber('orders', $orderYear)) + 1;
            $id = $this->nextId('orders');
            $recent = $daysAgo <= 60;
            $status = $custom
                ? (string) $this->weighted($recent ? ['in_progress' => 70, 'paused' => 8, 'execution_finished' => 12, 'closed' => 6, 'cancelled' => 4] : ['in_progress' => 8, 'paused' => 2, 'execution_finished' => 10, 'closed' => 72, 'cancelled' => 8])
                : (string) $this->weighted($recent ? ['draft' => 12, 'awaiting_payment' => 10, 'confirmed' => 30, 'partially_delivered' => 10, 'delivered' => 25, 'closed' => 8, 'cancelled' => 5] : ['draft' => 1, 'confirmed' => 2, 'delivered' => 12, 'closed' => 78, 'cancelled' => 7]);
            $assignee = $this->pick($this->sellers[$branch]);

            // Lines: a custom order holds needs (priced by its agreement later); a ready order holds catalog variants.
            $total_minor = 0;
            $lineCount = $custom ? mt_rand(1, 3) : mt_rand(1, 4);
            for ($l = 1; $l <= $lineCount; $l++) {
                if ($custom) {
                    $buffer['order_lines'][] = [
                        'id' => $this->nextId('order_lines'), 'order_id' => $id, 'position' => $l, 'line_type' => 'custom_need',
                        'snapshot' => '{"options":[]}', 'description' => $this->pick($needs), 'quantity' => '1.000', 'added_by' => $assignee,
                        'created_at' => $created, 'updated_at' => $created,
                    ];
                } else {
                    $variant = $this->variants[mt_rand(0, count($this->variants) - 1)];
                    $qty = mt_rand(1, 4);
                    $total_minor += $variant['price'] * $qty;
                    $buffer['order_lines'][] = [
                        'id' => $this->nextId('order_lines'), 'order_id' => $id, 'position' => $l, 'line_type' => 'ready_variant',
                        'catalog_version_id' => $variant['version_id'], 'catalog_item_type' => (new CatalogReadyVariant)->getMorphClass(),
                        'catalog_item_id' => $variant['id'], 'snapshot' => $variant['snapshot'], 'quantity' => $qty.'.000',
                        'unit_price_minor' => $variant['price'], 'line_total_minor' => $variant['price'] * $qty, 'added_by' => $assignee,
                        'created_at' => $created, 'updated_at' => $created,
                    ];
                }
            }
            $agreed = $custom ? (in_array($status, ['in_progress', 'paused'], true) && mt_rand(1, 100) <= 40 ? null : mt_rand(80, 2500) * 1000) : $total_minor;

            $buffer['orders'][] = [
                'id' => $id, 'number' => sprintf('ORD-%d-%05d', $orderYear, $numbers[$orderYear]), 'kind' => $custom ? 'custom' : 'ready',
                'status' => $status, 'customer_id' => $customer['id'], 'contact_id' => $customer['contact_id'], 'branch_id' => $branch,
                'assignee_id' => $assignee, 'created_by' => $assignee, 'need_summary' => $custom ? $this->pick($needs) : null,
                'needs_measurement' => $custom, 'needs_design' => $custom && mt_rand(0, 1) === 1,
                'invoice_type' => $customer['kind'] === 'organization' ? 'tax' : null, 'total_minor' => $agreed,
                'lock_version' => 1, 'created_at' => $created, 'updated_at' => $created,
            ];
            $buffer['state_transitions'][] = [
                'id' => $this->nextId('state_transitions'), 'machine' => $custom ? 'M-04' : 'M-05', 'transition' => 'T00',
                'subject_type' => $orderMorph, 'subject_id' => $id, 'from_state' => null, 'to_state' => $custom ? 'in_progress' : 'draft',
                'actor_user_id' => $assignee, 'source' => TransitionRecorder::SOURCE_SEEDER, 'context' => null, 'occurred_at' => $created,
            ];

            if ($custom) {
                $this->workflow($buffer, $template, $id, $branch, $assignee, $status, $created, $daysAgo, $stageCount, $taskTypes);
            }
            if ($agreed !== null && $status !== 'draft') {
                $this->money($buffer, $id, $customer['id'], $agreed, $status, $created, $daysAgo, $receipts);
            }
            $this->flush($buffer);
        }
        $this->flush($buffer, true);

        foreach ($numbers as $y => $last) {
            $this->counter('orders', $y, $last);
        }
        foreach ($receipts as $y => $last) {
            $this->counter('receipt', $y, $last);
        }
    }

    /**
     * Copies the template's stages and actions: stages before the active one completed, the active one in progress
     * with its task-creating actions ready and their tasks open, the rest not started. Closed orders are fully done.
     *
     * @param  array<string, list<array<string, mixed>>>  $buffer
     * @param  array{order: Order, version_id: int, stages: list<array<string, mixed>>, actions: list<array<string, mixed>>}  $template
     * @param  list<string>  $taskTypes
     */
    private function workflow(array &$buffer, array $template, int $orderId, int $branch, int $assignee, string $status, string $created, int $daysAgo, int $stageCount, array $taskTypes): void
    {
        $done = in_array($status, ['closed', 'execution_finished'], true);
        $active = match (true) {
            $done, $status === 'cancelled' => null,
            default => min($stageCount, 1 + (int) floor(min(1, (60 - min($daysAgo, 59)) / 60 + mt_rand(0, 30) / 100) * ($stageCount - 1))),
        };
        if ($status === 'cancelled') {
            $active = null;
        }
        $stageIds = [];
        $activeStageId = null;
        foreach ($template['stages'] as $stage) {
            $sid = $this->nextId('order_stages');
            $stageIds[$stage['id']] = $sid;
            $position = (int) $stage['position'];
            $stageStatus = match (true) {
                $done, $active !== null && $position < $active => StageStatus::Completed->value,
                $active === $position => $status === 'paused' ? StageStatus::Waiting->value : StageStatus::InProgress->value,
                default => StageStatus::NotStarted->value,
            };
            if ($active === $position) {
                $activeStageId = $sid;
            }
            $started = $stageStatus !== StageStatus::NotStarted->value ? $created : null;
            $buffer['order_stages'][] = [
                ...$stage, 'id' => $sid, 'order_id' => $orderId, 'status' => $stageStatus, 'started_at' => $started,
                'completed_at' => $stageStatus === StageStatus::Completed->value ? $created : null,
                'completed_by' => $stageStatus === StageStatus::Completed->value ? $assignee : null,
                'follow_up_user_id' => $stage['follow_up_user_id'] === null ? null : $assignee,
                'created_at' => $created, 'updated_at' => $created,
            ];
        }

        foreach ($template['actions'] as $action) {
            $aid = $this->nextId('order_actions');
            $stageId = $stageIds[$action['order_stage_id']];
            $position = (int) collect($template['stages'])->firstWhere('id', $action['order_stage_id'])['position'];
            $completedStage = $done || ($active !== null && $position < $active);
            $actionStatus = match (true) {
                $action['status'] === ActionStatus::NotRequired->value => ActionStatus::NotRequired->value,
                $completedStage => ActionStatus::Verified->value,
                $active === $position => $action['status'] === ActionStatus::Ready->value || mt_rand(1, 100) <= 50 ? ActionStatus::Ready->value : ActionStatus::Unavailable->value,
                default => ActionStatus::Unavailable->value,
            };
            $buffer['order_actions'][] = [
                ...$action, 'id' => $aid, 'order_id' => $orderId, 'order_stage_id' => $stageId, 'status' => $actionStatus,
                'held_by_action_id' => null, 'held_by_change_id' => null, 'source_reference' => null,
                'verified_at' => $actionStatus === ActionStatus::Verified->value ? $created : null,
                'created_at' => $created, 'updated_at' => $created,
            ];

            $makesTask = in_array($action['type'], $taskTypes, true) && $action['assign_type'] !== null && $action['status'] !== ActionStatus::NotRequired->value;
            if (! $makesTask || ($actionStatus !== ActionStatus::Ready->value && ! ($completedStage && mt_rand(1, 100) <= 60))) {
                continue;
            }
            $open = $actionStatus === ActionStatus::Ready->value;
            $taskStatus = $open
                ? (string) $this->weighted([TaskStatus::Unassigned->value => 20, TaskStatus::Assigned->value => 40, TaskStatus::InProgress->value => 30, TaskStatus::Waiting->value => 10])
                : TaskStatus::Completed->value;
            $department = $action['assign_type'] === 'department' ? $action['assign_id'] : $this->pick($this->departments);
            $taskAssignee = $taskStatus === TaskStatus::Unassigned->value ? null : $this->pick($this->executors);
            $due = $this->anchor->addDays(mt_rand(-10, 14))->setTime(mt_rand(8, 17), 0)->toDateTimeString();
            $taskId = $this->nextId('tasks');
            $buffer['tasks'][] = [
                'id' => $taskId, 'order_id' => $orderId, 'order_stage_id' => $stageId, 'order_action_id' => $aid, 'kind' => Task::KIND_ACTION,
                'title' => $action['name'], 'department_id' => $department, 'assignee_id' => $taskAssignee, 'follow_up_user_id' => $assignee,
                'status' => $taskStatus, 'due_at' => $due, 'waiting_need' => $taskStatus === TaskStatus::Waiting->value ? 'بانتظار العميل' : null,
                'assigned_at' => $taskAssignee === null ? null : $created, 'claimed_at' => $taskStatus === TaskStatus::InProgress->value ? $created : null,
                'completed_at' => $open ? null : $created, 'completed_by' => $open ? null : $taskAssignee,
                'lock_version' => 1, 'created_at' => $created, 'updated_at' => $created,
            ];

            // Measurement / installation visits: open ones around the anchor week, done ones in the past.
            if (mt_rand(1, 100) <= 35) {
                $start = $open
                    ? $this->anchor->addDays(mt_rand(-3, 10))->setTime(mt_rand(8, 17), mt_rand(0, 1) * 30)
                    : CarbonImmutable::parse($created)->addDays(mt_rand(1, 10))->setTime(mt_rand(8, 17), 0);
                $appointment = $this->nextId('appointments');
                $buffer['appointments'][] = [
                    'id' => $appointment, 'order_id' => $orderId, 'task_id' => $taskId, 'kind' => $this->pick(['measurement', 'installation', 'visit']),
                    'status' => $open ? $this->pick(['proposed', 'awaiting_confirmation', 'confirmed', 'confirmed']) : 'done',
                    'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->addMinutes(90)->toDateTimeString(),
                    'address' => 'حي '.$this->pick(['النرجس', 'الياسمين', 'الملقا', 'الروضة']).'، شارع '.mt_rand(1, 400),
                    'conflicted' => false, 'no_show' => false, 'created_by' => $assignee, 'lock_version' => 1,
                    'created_at' => $created, 'updated_at' => $created,
                ];
                $buffer['appointment_members'][] = ['id' => $this->nextId('appointment_members'), 'appointment_id' => $appointment, 'user_id' => $taskAssignee ?? $this->pick($this->executors)];
            }
        }

        $buffer['order_workflows'][] = [
            'id' => $this->nextId('order_workflows'), 'order_id' => $orderId, 'flow_version_id' => $template['version_id'],
            'active_stage_id' => $activeStageId, 'pause_reason' => $status === 'paused' ? 'بانتظار الموقع' : null,
            'paused_from' => $status === 'paused' ? 'in_progress' : null, 'created_at' => $created, 'updated_at' => $created,
        ];
    }

    /**
     * Dues (dated), confirmed payments with one receipt each and balanced journals: a collection puts the payment on
     * the order's credit (sum = payment), an allocation moves it to the oldest due (sum = 0). A few orders keep an
     * unallocated credit, a few await a proof decision.
     *
     * @param  array<string, list<array<string, mixed>>>  $buffer
     * @param  array<int, int>  $receipts
     */
    private function money(array &$buffer, int $orderId, int $customerId, int $amount, string $status, string $created, int $daysAgo, array &$receipts): void
    {
        $buffer['order_finances'][] = ['id' => $this->nextId('order_finances'), 'order_id' => $orderId, 'agreement_id' => null, 'lock_version' => 1, 'created_at' => $created, 'updated_at' => $created];

        $first = intdiv($amount, 2);
        $dues = [];
        foreach ([['first', 'الدفعة الأولى', 'First payment', $first, 0], ['final', 'الدفعة الأخيرة', 'Final payment', $amount - $first, 30]] as $p => [$ref, $ar, $en, $value, $offset]) {
            $dueId = $this->nextId('order_dues');
            $dues[] = ['id' => $dueId, 'amount' => $value];
            $buffer['order_dues'][] = [
                'id' => $dueId, 'order_id' => $orderId, 'position' => $p + 1, 'reference' => $ref,
                'name' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE), 'basis' => $p === 0 ? 'percent' : 'rest',
                'basis_value' => $p === 0 ? 5000 : null, 'amount_minor' => $value, 'due_event' => 'date',
                'due_date' => CarbonImmutable::parse($created)->addDays($offset)->toDateString(), 'created_at' => $created, 'updated_at' => $created,
            ];
        }

        $paidShare = match (true) {
            in_array($status, ['closed', 'delivered'], true) => 100,
            $status === 'cancelled' => 0,
            $daysAgo > 45 => $this->pick([50, 100, 100]),
            default => $this->pick([0, 0, 50, 50, 100]),
        };
        $toPay = intdiv($amount * $paidShare, 100);
        $credit = mt_rand(1, 100) <= 3 && $toPay > 0 ? mt_rand(1, 50) * 100 : 0;
        $payments = $toPay === 0 ? [] : ($toPay === $amount && mt_rand(0, 1) ? [$first, $amount - $first] : [$toPay]);
        if ($credit > 0) {
            $payments[count($payments) - 1] += $credit;
        }

        $dueLeft = array_column($dues, 'amount', 'id');
        foreach ($payments as $k => $value) {
            $paidOn = CarbonImmutable::parse($created)->addDays($k * 20 + mt_rand(0, 5));
            if ($paidOn->greaterThan($this->anchor)) {
                $paidOn = $this->anchor;
            }
            $at = $paidOn->setTime(12, 0)->toDateTimeString();
            $paymentId = $this->nextId('payments');
            $method = $this->pick(['transfer', 'transfer', 'cash', 'card']);
            $buffer['payments'][] = [
                'id' => $paymentId, 'customer_id' => $customerId, 'order_id' => $orderId, 'amount_minor' => $value, 'method' => $method,
                'paid_on' => $paidOn->toDateString(), 'reference' => $method === 'transfer' ? 'TRX'.$paymentId : null,
                'reference_key' => $method === 'transfer' ? 'trx'.$paymentId : null, 'origin' => Payment::ORIGIN_STAFF,
                'status' => 'confirmed', 'recorded_by' => $this->owner->id, 'confirmed_by' => $this->owner->id, 'confirmed_at' => $at,
                'lock_version' => 1, 'created_at' => $at, 'updated_at' => $at,
            ];
            $year = (int) $paidOn->format('Y');
            $receipts[$year] = ($receipts[$year] ?? $this->lastNumber('receipt', $year)) + 1;
            $buffer['receipts'][] = [
                'id' => $this->nextId('receipts'), 'number' => sprintf('RC-%d-%06d', $year, $receipts[$year]), 'payment_id' => $paymentId,
                'customer_id' => $customerId, 'order_id' => $orderId, 'amount_minor' => $value, 'issued_by' => $this->owner->id,
                'issued_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ];

            // Collection journal: the whole payment lands on the order's credit.
            $collection = $this->nextId('money_journals');
            $buffer['money_journals'][] = ['id' => $collection, 'kind' => 'collection', 'payment_id' => $paymentId, 'actor_user_id' => $this->owner->id, 'source' => 'seeder', 'created_at' => $at];
            $buffer['money_entries'][] = ['id' => $this->nextId('money_entries'), 'journal_id' => $collection, 'payment_id' => $paymentId, 'order_id' => $orderId, 'order_due_id' => null, 'amount_minor' => $value, 'created_at' => $at];

            // Allocation journal: oldest due first, never above a due; what is left stays as credit. Sums to zero.
            $remaining = $value;
            $moves = [];
            foreach ($dueLeft as $dueId => $left) {
                $take = min($left, $remaining);
                if ($take > 0) {
                    $moves[] = [$dueId, $take];
                    $dueLeft[$dueId] -= $take;
                    $remaining -= $take;
                }
            }
            if ($moves !== []) {
                $allocation = $this->nextId('money_journals');
                $buffer['money_journals'][] = ['id' => $allocation, 'kind' => 'allocation', 'payment_id' => $paymentId, 'actor_user_id' => $this->owner->id, 'source' => 'seeder', 'created_at' => $at];
                foreach ($moves as [$dueId, $take]) {
                    $buffer['money_entries'][] = ['id' => $this->nextId('money_entries'), 'journal_id' => $allocation, 'payment_id' => $paymentId, 'order_id' => $orderId, 'order_due_id' => null, 'amount_minor' => -$take, 'created_at' => $at];
                    $buffer['money_entries'][] = ['id' => $this->nextId('money_entries'), 'journal_id' => $allocation, 'payment_id' => $paymentId, 'order_id' => $orderId, 'order_due_id' => $dueId, 'amount_minor' => $take, 'created_at' => $at];
                }
            }
        }

        // A transfer proof waiting for the finance decision on some open orders.
        if ($paidShare < 100 && $status !== 'cancelled' && $daysAgo <= 30 && mt_rand(1, 100) <= 25) {
            $proof = $this->nextId('payment_proofs');
            $buffer['payment_proofs'][] = [
                'id' => $proof, 'order_id' => $orderId, 'customer_id' => $customerId, 'claimed_minor' => $amount - $toPay,
                'method' => 'transfer', 'paid_on' => $this->anchor->subDays(mt_rand(0, 5))->toDateString(), 'reference' => 'PRF'.$proof,
                'reference_key' => 'prf'.$proof, 'status' => 'under_review', 'lock_version' => 1,
                'created_at' => $this->at(mt_rand(0, 5)), 'updated_at' => $this->at(0),
            ];
        }
    }

    /**
     * ≈200,000 movements over every (branch warehouse × variant) pair, replayed in time order so each row carries
     * the running balance; the projected balance equals the sum of the pair's movements. Reservations for confirmed
     * ready orders are left out: the movement history alone is what the stock screens read.
     */
    private function stock(): void
    {
        $type = StockItem::READY_VARIANT;
        $buffer = ['stock_items' => [], 'stock_movements' => [], 'stock_balances' => []];
        $items = [];
        foreach ($this->variants as $variant) {
            $existing = DB::table('stock_items')->where('item_type', $type)->where('item_id', $variant['id'])->value('id');
            if ($existing === null) {
                $existing = $this->nextId('stock_items');
                $buffer['stock_items'][] = ['id' => $existing, 'item_type' => $type, 'item_id' => $variant['id'], 'unit' => 'piece', 'reorder_level_milli' => mt_rand(0, 1) ? 5000 : null, 'lock_version' => 1, 'created_at' => $this->at(800), 'updated_at' => $this->at(800)];
            }
            $items[] = (int) $existing;
        }
        $this->flush($buffer, true);

        $pairs = [];
        foreach ($this->locations as $location) {
            foreach ($items as $item) {
                $pairs[] = [$location, $item];
            }
        }
        $perPair = max(2, intdiv($this->n(200000), count($pairs)));
        $docs = 0;

        foreach ($pairs as [$location, $item]) {
            $onHand = 0;
            $reserved = 0;
            $day = 760;
            for ($m = 0; $m < $perPair; $m++) {
                $day = max(0, $day - mt_rand(0, (int) ceil(1500 / $perPair)));
                [$kind, $handDelta, $reservedDelta] = match (true) {
                    $m === 0 || $onHand < 3000 => [MovementKind::Receipt, mt_rand(10, 40) * 1000, 0],
                    $reserved >= 1000 && mt_rand(1, 100) <= 50 => ($q = min($reserved, mt_rand(1, 3) * 1000)) && mt_rand(0, 3) > 0
                        ? [MovementKind::IssueReserved, -$q, -$q] : [MovementKind::Release, 0, -$q],
                    default => (function () use ($onHand, $reserved) {
                        $free = $onHand - $reserved;
                        $roll = mt_rand(1, 100);

                        return match (true) {
                            $roll <= 35 && $free >= 1000 => [MovementKind::Reserve, 0, min($free, mt_rand(1, 3) * 1000)],
                            $roll <= 70 && $free >= 1000 => [MovementKind::Issue, -min($free, mt_rand(1, 3) * 1000), 0],
                            $roll <= 80 => [MovementKind::ReturnIn, 1000, 0],
                            $roll <= 90 && $free >= 1000 => [MovementKind::AdjustmentOut, -1000, 0],
                            default => [MovementKind::AdjustmentIn, mt_rand(1, 5) * 1000, 0],
                        };
                    })(),
                };
                $onHand += $handDelta;
                $reserved += $reservedDelta;
                $at = $this->at($day, mt_rand(8, 18));
                $buffer['stock_movements'][] = [
                    'id' => $this->nextId('stock_movements'), 'kind' => $kind->value, 'location_id' => $location, 'stock_item_id' => $item,
                    'unit' => 'piece', 'quantity_milli' => abs($handDelta !== 0 ? $handDelta : $reservedDelta),
                    'on_hand_delta' => $handDelta, 'reserved_delta' => $reservedDelta, 'on_hand_after' => $onHand, 'reserved_after' => $reserved,
                    'document_number' => in_array($kind, [MovementKind::AdjustmentIn, MovementKind::AdjustmentOut, MovementKind::Receipt], true) ? sprintf('SA-P-%07d', ++$docs) : null,
                    'reason' => null, 'actor_id' => $this->owner->id, 'occurred_at' => $at, 'created_at' => $at,
                ];
            }
            $buffer['stock_balances'][] = [
                'id' => $this->nextId('stock_balances'), 'location_id' => $location, 'stock_item_id' => $item,
                'on_hand_milli' => $onHand, 'reserved_milli' => $reserved, 'created_at' => $this->at(760), 'updated_at' => $this->at(0),
            ];
            $this->flush($buffer);
        }
        $this->flush($buffer, true);
    }

    /**
     * Bulk numbers continue the gapless counters (the template order already took one).
     */
    private function lastNumber(string $name, int $period): int
    {
        return (int) DB::table('sequence_counters')->where('name', $name)->where('period', $period)->value('last_value');
    }

    private function counter(string $name, int $period, int $last): void
    {
        DB::table('sequence_counters')->updateOrInsert(['name' => $name, 'period' => $period], ['last_value' => max($this->lastNumber($name, $period), $last)]);
    }
}

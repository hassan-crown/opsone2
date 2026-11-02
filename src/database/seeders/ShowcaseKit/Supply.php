<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\Inventory\ManageStockSetup;
use App\Actions\Inventory\StockCounts;
use App\Actions\Procurement\SaveSupplier;
use App\Actions\StateMachines\M13PurchaseOrder\T00CreatePurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T01ConfirmPurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T02ReceivePurchase;
use App\Actions\StateMachines\M13PurchaseOrder\T03CancelRemainder;
use App\Actions\StateMachines\M25Transfer\T00CreateTransfer;
use App\Actions\StateMachines\M25Transfer\T01DispatchTransfer;
use App\Actions\StateMachines\M25Transfer\T02ReceiveTransfer;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockTransfer;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Suppliers, purchase orders (fully and partially received, one with a rejected part and one cancelled remainder),
 * opening stock in every branch location, transfers between branches and periodic stock counts.
 */
final class Supply
{
    /** @var array<string, Supplier> */
    public array $suppliers = [];

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        Timeline::freeze(CarbonImmutable::parse('2026-04-23 09:00', 'Asia/Riyadh'));
        $this->suppliers();
        $this->opening();
        $this->recurring();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function suppliers(): void
    {
        $save = app(SaveSupplier::class);
        $rows = [
            'hardware' => ['الحديد الألماني للمعدات', 'German Iron Hardware', 'مفصلات ومجاري أدراج ومعدات الخزائن', 'أنس العلي'],
            'handles' => ['شركة المقابض الذهبية', 'Golden Handles Co.', 'مقابض ومسكات وبروفايلات', 'سامر الحداد'],
            'sanitary' => ['مؤسسة الخلاطات الراقية', 'Premium Mixers Est.', 'خلاطات وأحواض مطبخ', 'فراس النجار'],
            'lighting' => ['شركة الإضاءة الحديثة', 'Modern Lighting Co.', 'شرائط LED ومحولات وكشافات', 'رامي السعدي'],
            'organizers' => ['مصنع المنظمات الذكية', 'Smart Organizers Factory', 'منظمات الأدراج وملحقات التخزين', 'ياسر خوري'],
            'boards' => ['شركة الألواح المتقدمة', 'Advanced Boards Co.', 'ألواح MDF وشرائط الحواف', 'عماد الشريف'],
        ];
        foreach ($rows as $key => [$ar, $en, $supplies, $contact]) {
            $this->suppliers[$key] = app(SaveSupplier::class)->handle($this->s->users['inventory'], null, [
                'name' => ['ar' => $ar, 'en' => $en], 'contact_name' => $contact, 'phone' => '+9661'.$this->s->rng->int(1000000, 9999999),
                'email' => 'sales'.count($this->suppliers).'@supplier-demo.test', 'vat_number' => '3'.str_pad((string) $this->s->rng->int(0, 9999999999999), 13, '0', STR_PAD_LEFT).'3',
                'supplies' => $supplies, 'notes' => 'الدفع خلال 30 يومًا من الاستلام.',
            ], null, $this->key());
        }
    }

    private function supplierFor(string $category): Supplier
    {
        return $this->suppliers[match ($category) {
            'handles' => 'handles', 'hinges', 'drawers' => 'hardware', 'organizers' => 'organizers', 'mixers' => 'sanitary', 'led' => 'lighting', default => 'hardware',
        }];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $type, int $id, int $milli, ?int $cost): array
    {
        return ['id' => null, 'item_type' => $type, 'item_id' => $id, 'quantity_milli' => $milli, 'unit_cost_minor' => $cost, 'order_id' => null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(string $category, int $min, int $max): array
    {
        $lines = [];
        foreach ($this->s->catalog->byCategory[$category] ?? [] as $variant) {
            $lines[] = $this->line('ready_variant', $variant->id, $this->s->rng->int($min, $max) * 1000, (int) round($variant->price_minor * 0.55));
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function purchase(string $branch, Supplier $supplier, array $lines, ?string $note = null): PurchaseOrder
    {
        $inv = $this->s->users['inventory'];
        $po = app(T00CreatePurchaseOrder::class)->handle($inv, [
            'supplier_id' => $supplier->id, 'branch_id' => $this->s->branches[$branch]->id, 'location_id' => $this->s->catalog->locations[$branch]->id,
            'expected_on' => CarbonImmutable::now()->addDays($this->s->rng->int(5, 12))->toDateString(), 'responsible_id' => $inv->id, 'notes' => $note, 'lines' => $lines,
        ], $this->key());

        return app(T01ConfirmPurchaseOrder::class)->handle($inv, $po->refresh(), $po->lock_version, $this->key());
    }

    /**
     * @param  array<int, array{0: int, 1?: int, 2?: string}>|null  $byLine  null = receive everything remaining
     */
    private function receive(string $branch, PurchaseOrder $po, ?array $byLine = null, float $fraction = 1.0): void
    {
        $po->refresh()->load('lines');
        $lines = [];
        foreach ($po->lines as $line) {
            $remaining = $line->remainingMilli();
            if ($remaining <= 0) {
                continue;
            }
            $accepted = $byLine[$line->line_no][0] ?? (int) (floor($remaining * $fraction / 1000) * 1000);
            $accepted = max(1000, min($remaining, $accepted));
            $lines[] = ['line_id' => $line->id, 'accepted_milli' => $accepted - ($byLine[$line->line_no][1] ?? 0), 'rejected_milli' => $byLine[$line->line_no][1] ?? 0, 'reject_reason' => $byLine[$line->line_no][2] ?? null];
        }
        if ($lines === []) {
            return;
        }
        app(T02ReceivePurchase::class)->handle($this->s->users['inventory'], $po, [
            'location_id' => $po->location_id, 'received_on' => CarbonImmutable::now()->toDateString(), 'reference' => 'DN-'.$this->s->rng->int(1000, 9999), 'note' => null, 'lines' => $lines,
        ], [Assets::pdf('supplier-delivery-'.$po->number.'.pdf', 'Delivery '.$po->number)], $po->lock_version, $this->key());
    }

    /**
     * Opening stock (Apr 27–29, before the first day) in the three warehouses plus the Riyadh showroom.
     */
    private function opening(): void
    {
        $story = new Story('opening-stock');
        $day = CarbonImmutable::parse('2026-04-27 09:30', 'Asia/Riyadh');
        $plan = [['RUH', 40, 90], ['JED', 20, 50], ['DMM', 15, 40]];
        foreach ($plan as $n => [$branch, $min, $max]) {
            foreach (array_keys($this->s->catalog->byCategory) as $i => $category) {
                $when = $day->addDays((int) floor(($n * 7 + $i) / 4))->addMinutes($i * 25 + $n * 7);
                while (in_array($when->dayOfWeek, [5, 6], true)) {
                    $when = $when->addDay();
                }
                $this->s->timeline->at($when, $story, "open-po-{$branch}-{$category}", function () use ($branch, $category, $min, $max) {
                    $po = $this->purchase($branch, $this->supplierFor($category), $this->lines($category, $min, $max), 'مخزون افتتاحي قبل بدء التشغيل');
                    $this->receive($branch, $po);
                });
            }
        }
        // Tracked materials arrive in Riyadh (the workshop).
        $this->s->timeline->at($day->addDays(1)->setTime(11, 0), $story, 'open-materials', function () {
            $m = $this->s->catalog->materials;
            $setup = app(ManageStockSetup::class);
            foreach (['mdf_moisture' => 'sheet', 'mdf_laminate' => 'sheet', 'hinge_hardware' => 'piece', 'slide_hardware' => 'pair', 'edge_banding' => 'meter', 'led_profile' => 'meter'] as $key => $unit) {
                $setup->updateItem($this->s->users['inventory'], 'material', $m[$key]->id, $unit, 20000);
            }
            $lines = [];
            foreach (['mdf_moisture' => [60, 120, 7500], 'mdf_laminate' => [40, 90, 6500], 'hinge_hardware' => [400, 900, 700], 'slide_hardware' => [150, 300, 2200], 'edge_banding' => [500, 1200, 180], 'led_profile' => [100, 260, 950]] as $key => [$min, $max, $cost]) {
                $lines[] = $this->line('material', $m[$key]->id, $this->s->rng->int($min, $max) * 1000, $cost);
            }
            $po = $this->purchase('RUH', $this->suppliers['boards'], $lines, 'خامات الورشة للشهر الأول');
            $this->receive('RUH', $po);
        });
        // Showroom display stock moved from the Riyadh warehouse.
        $this->s->timeline->at($day->addDays(5)->setTime(10, 0), $story, 'showroom-transfer', function () {
            $setup = app(ManageStockSetup::class);
            foreach ($this->s->catalog->variants as $variant) {
                $setup->updateItem($this->s->users['inventory'], 'ready_variant', $variant->id, 'piece', 8000);
            }
            $lines = [];
            foreach ($this->s->catalog->variants as $variant) {
                $lines[] = ['item_type' => 'ready_variant', 'item_id' => $variant->id, 'quantity_milli' => 6000];
            }
            $loc = $this->s->catalog->locations;
            $t = app(T00CreateTransfer::class)->handle($this->s->users['inventory'], $loc['RUH']->id, $loc['RUH-SHOW']->id, $lines, 'عرض المنتجات في المعرض', true);
        });
    }

    private function recurring(): void
    {
        $rng = $this->s->rng;
        $span = (int) $this->s->start->diffInDays($this->s->end);
        $story = new Story('replenishment');
        $categories = array_keys($this->s->catalog->byCategory);

        // Replenishment purchases: every ~10 days somewhere, a mix of full, partial and problematic receipts.
        $n = $this->s->n(34);
        for ($i = 0; $i < $n; $i++) {
            $when = $this->s->start->addDays($rng->int(3, $span - 3))->setTime($rng->int(9, 14), 0);
            while (in_array($when->dayOfWeek, [5, 6], true)) {
                $when = $when->addDay();
            }
            $branch = $rng->weighted(['RUH' => 50, 'JED' => 28, 'DMM' => 22]);
            $category = $rng->pick($categories);
            $mode = $rng->weighted(['full' => 55, 'partial' => 30, 'reject' => 8, 'cancel' => 7]);
            $late = $rng->int(4, 9);
            $s2 = new Story("po-{$i}");
            $this->s->timeline->at($when, $s2, 'po', function (Story $st) use ($branch, $category, $mode, $late) {
                $po = $this->purchase($branch, $this->supplierFor($category), $this->lines($category, 10, 40));
                $st->data['po'] = $po->id;
                $at = CarbonImmutable::now()->addDays($late)->setTime(11, 0);
                while (in_array($at->dayOfWeek, [5, 6], true)) {
                    $at = $at->addDay();
                }
                $this->s->timeline->at($at, $st, 'receive', function (Story $st) use ($branch, $mode, $late) {
                    $po = PurchaseOrder::query()->findOrFail($st->int('po'));
                    if ($mode === 'full') {
                        $this->receive($branch, $po);
                    } elseif ($mode === 'reject') {
                        $first = $po->refresh()->load('lines')->lines->first();
                        $this->receive($branch, $po, [$first->line_no => [$first->quantity_milli, 2000, 'خدوش في التغليف عند الاستلام']]);
                    } else {
                        $this->receive($branch, $po, null, 0.6);
                        $st->data['partial'] = true;
                        $next = CarbonImmutable::now()->addDays($late + 3)->setTime(12, 0);
                        while (in_array($next->dayOfWeek, [5, 6], true)) {
                            $next = $next->addDay();
                        }
                        $this->s->timeline->at($next, $st, 'receive-rest', function (Story $st) use ($branch, $mode) {
                            $po = PurchaseOrder::query()->findOrFail($st->int('po'));
                            if ($mode === 'cancel') {
                                app(T03CancelRemainder::class)->handle($this->s->users['inventory'], $po, 'المورد لم يتمكن من توريد باقي الكمية؛ أُلغي المتبقي.', $po->lock_version, $this->key());

                                return;
                            }
                            $this->receive($branch, $po);
                        });
                    }
                });
            });
        }

        // Transfers from the Riyadh warehouse to the other branches (dispatched, then received next day).
        $transfers = $this->s->n(14);
        for ($i = 0; $i < $transfers; $i++) {
            $when = $this->s->start->addDays($rng->int(15, $span - 4))->setTime(10, 0);
            while (in_array($when->dayOfWeek, [5, 6], true)) {
                $when = $when->addDay();
            }
            $to = $rng->pick(['JED', 'DMM']);
            $cat = $rng->pick($categories);
            $st = new Story("transfer-{$i}");
            $this->s->timeline->at($when, $st, 'transfer', function (Story $st) use ($to, $cat) {
                $loc = $this->s->catalog->locations;
                $lines = [];
                $from = $loc['RUH']->id;
                foreach (array_slice($this->s->catalog->byCategory[$cat], 0, 3) as $v) {
                    $item = StockItem::query()->where('item_type', 'ready_variant')->where('item_id', $v->id)->first();
                    $b = $item === null ? null : StockBalance::query()->where('location_id', $from)->where('stock_item_id', $item->id)->first();
                    $free = $b === null ? 0 : $b->on_hand_milli - $b->reserved_milli;
                    $qty = min($this->s->rng->int(3, 8) * 1000, (int) (floor($free / 2000) * 1000));
                    if ($qty >= 1000) {
                        $lines[] = ['item_type' => 'ready_variant', 'item_id' => $v->id, 'quantity_milli' => $qty];
                    }
                }
                if ($lines === []) {
                    return;
                }
                $inv = $this->s->users['inventory'];
                $t = app(T00CreateTransfer::class)->handle($inv, $loc['RUH']->id, $loc[$to]->id, $lines, 'تعزيز مخزون الفرع', false);
                $t = app(T01DispatchTransfer::class)->handle($inv, $t->refresh(), $t->lock_version);
                $st->data['transfer'] = $t->id;
                $this->s->timeline->at(CarbonImmutable::now()->addDay()->setTime(14, 0), $st, 'receive', function (Story $st) {
                    $t = StockTransfer::query()->findOrFail($st->int('transfer'));
                    app(T02ReceiveTransfer::class)->handle($this->s->users['inventory'], $t, $t->lock_version);
                });
            });
        }

        // Month-end counts at the warehouses; differences are approved by the manager and posted as adjustments.
        foreach (['2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-29'] as $m => $date) {
            foreach (['RUH', 'JED'] as $branch) {
                $when = CarbonImmutable::parse($date.' 16:00', 'Asia/Riyadh');
                while (in_array($when->dayOfWeek, [5, 6], true)) {
                    $when = $when->subDay();
                }
                $st = new Story("count-{$branch}-{$m}");
                $this->s->timeline->at($when->addMinutes($branch === 'JED' ? 40 : 0), $st, 'count', fn () => $this->count($branch));
            }
        }
    }

    private function count(string $branch): void
    {
        $location = $this->s->catalog->locations[$branch];
        $lines = [];
        $rows = StockBalance::query()->where('location_id', $location->id)->where('on_hand_milli', '>', 0)->orderBy('id')->get();
        foreach ($rows->take(18) as $b) {
            $item = StockItem::query()->findOrFail($b->stock_item_id);
            $diff = $this->s->rng->chance(18) ? -1000 * $this->s->rng->int(1, 2) : 0;
            $lines[] = ['item_type' => $item->item_type, 'item_id' => $item->item_id, 'counted_milli' => max($b->reserved_milli, $b->on_hand_milli + $diff)];
        }
        $inv = $this->s->users['inventory'];
        $counts = app(StockCounts::class);
        $count = $counts->create($inv, $location, 'جرد دوري نهاية الشهر', $lines);
        $count = $counts->submit($inv, $count->refresh(), $count->lock_version);
        $counts->approve($this->s->users['owner'], $count->refresh(), $count->lock_version, 'تمت المراجعة واعتماد فروقات الجرد.');
    }
}

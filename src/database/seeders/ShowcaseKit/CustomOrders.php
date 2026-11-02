<?php

namespace Database\Seeders\ShowcaseKit;

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Orders\CreateOrders;
use App\Actions\Orders\ManageOrderLines;
use App\Actions\Orders\UpdateOrder;
use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T02StartTask;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M08Document\T00CreateQuote;
use App\Actions\StateMachines\M08Document\T00PrepareContract;
use App\Actions\StateMachines\M08Document\T00UploadDesign;
use App\Actions\StateMachines\M08Document\T01SendDocument;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Actions\StateMachines\M10Proof\T01ConfirmProof;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Actions\StateMachines\M14Delivery\T00CreateDraft;
use App\Actions\StateMachines\M14Delivery\T01ConfirmDelivery;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T01SendToCustomer;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Actions\StateMachines\M16Appointment\T05CompleteVisit;
use App\Actions\StateMachines\M26Decision\T00RequestDiscount;
use App\Actions\StateMachines\M26Decision\T01ApproveDiscount;
use App\Actions\Workflow\RecordMeasurement;
use App\Agreements\CustomerActor;
use App\Agreements\CustomerDecisions;
use App\Enums\TaskStatus;
use App\Files\AttachmentService;
use App\Finance\CustomerProofs;
use App\Finance\MoneyLedger;
use App\Inventory\Quantity;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Finance\PaymentProof;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Task;
use App\Models\User;
use App\Money\Money;
use App\Workflow\StageEngine;
use App\Workflow\TaskQueries;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The life of one made-to-order kitchen, walked through the real workflow: measurement → design → quote and
 * agreement → deposit → manufacturing → installation → final payment → delivery. Every step is a timed event run
 * under the frozen clock by the Timeline, using the same Actions the screens call.
 */
final class CustomOrders
{
    private const WORK_DAYS = [0, 1, 2, 3, 4]; // Sun..Thu

    public function __construct(private readonly Showcase $s) {}

    /**
     * Opens a made-to-order kitchen for `$customerId` at `$when` (a direct order) and schedules the rest.
     *
     * @param  array<string, mixed>  $opts
     */
    public function openDirect(CarbonImmutable $when, string $branch, int $customerId, array $opts = []): void
    {
        $story = new Story('custom-direct-'.$customerId.'-'.$when->format('md'));
        $this->s->timeline->at($when, $story, 'open', function (Story $st) use ($branch, $customerId, $opts) {
            $sales = $this->salesOf($branch);
            $res = app(CreateOrders::class)->handle($sales, (string) Str::uuid(), [
                'customer_id' => $customerId, 'branch_id' => $this->s->branches[$branch]->id, 'assignee_id' => $sales->id,
                'custom' => ['need_summary' => $opts['need'] ?? $this->s->rng->pick(People::needs()), 'needs_measurement' => true, 'needs_design' => true, 'lines' => []],
            ]);
            $st->data['order'] = $res['result']['order_ids'][0];
            $this->plan($st, $branch, $opts);
        });
    }

    /**
     * Continues the story of an order that already exists (opened by converting an interest).
     *
     * @param  array<string, mixed>  $opts
     */
    public function continueOrder(Order $order, string $branch, array $opts = []): void
    {
        $story = new Story('custom-'.$order->number);
        $story->data['order'] = $order->id;
        $this->s->timeline->at(CarbonImmutable::now()->addMinutes(20), $story, 'plan', function (Story $st) use ($branch, $opts) {
            $this->plan($st, $branch, $opts);
        });
    }

    /**
     * Draws every random choice of the order up front and schedules the whole timeline.
     *
     * @param  array<string, mixed>  $opts
     */
    private function plan(Story $st, string $branch, array $opts): void
    {
        $rng = $this->s->rng;
        $now = CarbonImmutable::now();
        $product = $rng->pick(array_keys($this->s->catalog->products));
        $length = $rng->int(28, 85) / 10;               // metres
        $mult = $rng->int(92, 112) / 100;               // price negotiation around the reference price
        $discount = $opts['discount'] ?? $rng->weighted(['0' => 50, '3' => 18, '5' => 14, '8' => 10, '12' => 8]);
        $viaPortal = $rng->chance(15);
        $proofByCustomer = $rng->chance(30);
        $deposit = $rng->chance(25);

        $at = fn (float $days, int $hour = 10) => $now->addMinutes((int) ($days * 1440))->setTime($hour, $rng->int(0, 5) * 10);
        $d = 0.0;
        foreach (['viaPortal', 'proofByCustomer', 'deposit'] as $flag) {
            ${$flag} = $opts[$flag] ?? ${$flag};
        }
        $st->data += compact('product', 'length', 'mult', 'discount', 'viaPortal', 'proofByCustomer', 'deposit') + ['branch' => $branch, 'stopAfter' => $opts['stopAfter'] ?? null, 'unconfirmed' => $opts['unconfirmed'] ?? [],
            'esign' => $opts['esign'] ?? null, 'contract' => $opts['contract'] ?? null, 'signer' => $opts['signer'] ?? null];

        $this->step($st, $at(0.02), 'lines', fn () => $this->setupLines($st));
        // Needs and measurement
        $d += 0.3;
        $this->step($st, $at($d, 11), 'needs', fn () => $this->doTask($st, 'needs', 'sales'));
        $visitDay = $d + $rng->int(1, 4);
        $this->step($st, $at($d + 0.4, 12), 'measure-appt', fn () => $this->bookVisit($st, 'measurement', $visitDay));
        $d = $visitDay + 0.6;
        $this->step($st, $at($d, 15), 'measure', fn () => $this->doMeasure($st));
        $d += 0.1;
        $this->step($st, $at($d, 16), 'site-photos', fn () => $this->sitePhotos($st));
        $d += 0.3;
        $this->step($st, $at($d, 13), 'stage-measure', fn () => $this->completeStage($st, 'measure'));
        // Design
        $d += 0.2;
        $this->step($st, $at($d, 10), 'design-claim', fn () => $this->claimOrAssign($st, 'design'));
        $d += $rng->int(2, 5);
        $this->step($st, $at($d, 14), 'design-upload', fn () => $this->uploadDesign($st));
        $d += $rng->int(1, 3);
        $this->step($st, $at($d, 12), 'design-approve', fn () => $this->approveDesign($st));
        if ($deposit) {
            $this->step($st, $at($d + 0.1, 15), 'deposit', fn () => $this->payDeposit($st));
        }
        $this->step($st, $at($d + 0.3, 15), 'design-done', fn () => $this->doTaskAfterDesign($st));
        $d += 0.5;
        $this->step($st, $at($d, 9), 'quote', fn () => $this->prepareQuote($st));
        $d += 0.1;
        $this->step($st, $at($d, 11), 'quote-send', fn () => $this->sendQuote($st));
        $d += $rng->int(1, 4);
        $this->step($st, $at($d, 13), 'quote-approve', fn () => $this->approveQuote($st));
        $this->step($st, $at($d + 0.3, 11), 'stage-design', fn () => $this->completeStage($st, 'design'));
        // Agreement and first payment
        $d += $rng->int(0, 2) + 0.4;
        $this->step($st, $at($d, 12), 'first-payment', fn () => $this->payFirst($st));
        if ($proofByCustomer) {
            $this->step($st, $at($d + 0.5, 11), 'confirm-proof', fn () => $this->confirmProof($st));
        }
        $d += 0.8;
        $this->step($st, $at($d, 10), 'stage-agreement', fn () => $this->completeStage($st, 'agreement'));
        // Manufacturing
        $d += 0.2;
        $this->step($st, $at($d, 9), 'make-claim', fn () => $this->claimOrAssign($st, 'manufacturing'));
        $d += $rng->int(12, 24);
        $this->step($st, $at($d, 15), 'make-done', fn () => $this->doTaskNamed($st, 'manufacturing'));
        $d += 0.3;
        $this->step($st, $at($d, 9), 'qc-claim', fn () => $this->claimOrAssign($st, 'quality_check'));
        $d += 1;
        $this->step($st, $at($d, 13), 'qc-done', fn () => $this->doTaskNamed($st, 'quality_check'));
        $this->step($st, $at($d + 0.2, 14), 'stage-manufacture', fn () => $this->completeStage($st, 'manufacture'));
        // Installation
        $d += 0.3;
        $installDay = $d + $rng->int(2, 6);
        $this->step($st, $at($d, 11), 'install-appt', fn () => $this->bookVisit($st, 'installation', $installDay));
        $this->step($st, $at($d + 0.1, 12), 'install-claim', fn () => $this->claimOrAssign($st, 'installation'));
        $d = $installDay + 0.5;
        // Normally the field app's «انتهى» already completed it; otherwise its holder closes it as before.
        $this->step($st, $at($d, 16), 'install-done', function () use ($st) {
            if ($this->openTask($this->order($st), 'installation') !== null) {
                $this->doTaskNamed($st, 'installation');
            }
        });
        $d += 0.4;
        $this->step($st, $at($d, 10), 'final-payment', fn () => $this->payFinal($st));
        $d += 0.2;
        $this->step($st, $at($d, 12), 'delivery', fn () => $this->deliver($st));
        $this->step($st, $at($d + 0.05, 12), 'tax-invoice', fn () => $this->taxInvoice($st));
        $this->step($st, $at($d + 0.1, 13), 'stage-install', fn () => $this->completeStage($st, 'install'));
    }

    private function step(Story $st, CarbonImmutable $when, string $label, \Closure $fn): void
    {
        if ($st->data['_stopped'] ?? false) {
            return;
        }
        if (($st->data['stopAfter'] ?? null) === $label) {
            $st->data['_stopped'] = true; // the story is deliberately left here (customer workspace showcase)
        }
        // Working days only (Friday and Saturday are the weekend), and never before the previous step of the story.
        while (! in_array($when->dayOfWeek, self::WORK_DAYS, true)) {
            $when = $when->addDay();
        }
        $last = $st->data['_last'] ?? CarbonImmutable::now();
        if ($when->lessThanOrEqualTo($last)) {
            $when = $last->addMinutes(20);
            while (! in_array($when->dayOfWeek, self::WORK_DAYS, true)) {
                $when = $when->addDay()->setTime(9, 0);
            }
        }
        $st->data['_last'] = $when;
        $this->s->timeline->at($when, $st, $label, function () use ($st, $fn) {
            if (isset($st->data['order']) && Order::query()->whereKey($st->data['order'])->value('status') === 'cancelled') {
                $st->alive = false; // cancelled by management: the rest of its plan no longer applies

                return;
            }
            $fn();
        });
    }

    // ---------------------------------------------------------------- helpers

    private function order(Story $st): Order
    {
        return Order::query()->findOrFail($st->int('order'));
    }

    private function salesOf(string $branch): User
    {
        $keys = ['RUH' => ['sales_ruh1', 'sales_ruh2'], 'JED' => ['sales_jed'], 'DMM' => ['sales_dmm']][$branch];

        return $this->s->users[$this->s->rng->pick($keys)];
    }

    private function sales(Story $st): User
    {
        return User::query()->findOrFail($this->order($st)->assignee_id);
    }

    private function manager(Story $st): User
    {
        return $this->s->users[['RUH' => 'gm', 'JED' => 'mgr_jed', 'DMM' => 'mgr_dmm'][$st->data['branch']]];
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function customerActor(Order $order): CustomerActor
    {
        $customer = Customer::query()->findOrFail($order->customer_id);

        return new CustomerActor($customer->id, $customer->mobile);
    }

    private function setupLines(Story $st): void
    {
        $order = $this->order($st);
        $product = $this->s->catalog->products[$st->data['product']];
        $sales = $this->sales($st);
        $options = [
            ['key' => 'color', 'value' => $this->s->rng->pick(['أبيض', 'رمادي', 'بلوط فاتح', 'أسود'])],
            ['key' => 'door', 'value' => $this->s->rng->pick(['أكريليك لامع', 'أكريليك مطفي', 'خشب طبيعي', 'لامينيت'])],
            ['key' => 'top', 'value' => $this->s->rng->pick(['كوارتز', 'رخام'])],
            ['key' => 'length', 'value' => (string) $st->data['length']],
        ];
        app(ManageOrderLines::class)->add($sales, $order, $order->lock_version, [
            'type' => 'custom_product', 'product_id' => $product->id, 'quantity' => (string) $st->data['length'], 'unit' => 'm', 'options' => $options,
        ]);
        $order->refresh();
        app(UpdateOrder::class)->handle($sales, $order, $order->lock_version, ['needs_measurement' => true, 'needs_design' => true, 'invoice_type' => 'tax']);
    }

    /**
     * The open task of the action (assigned or in the department inbox).
     */
    private function openTask(Order $order, string $actionKey): ?Task
    {
        return Task::query()->whereHas('action', fn ($q) => $q->where('order_id', $order->id)->where('key', $actionKey))
            ->whereIn('status', TaskStatus::open())->orderByDesc('id')->first();
    }

    private function claimOrAssign(Story $st, string $actionKey): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, $actionKey) ?? throw new \RuntimeException("no open task {$actionKey}");
        if ($task->status !== TaskStatus::Unassigned) {
            return;
        }
        $task->setRelation('order', $order);
        $eligible = TaskQueries::eligibleFor($this->s->users['owner'], $task);
        if ($eligible === []) {
            throw new \RuntimeException('nobody eligible for '.$actionKey);
        }
        // By user id, so the seeded pick never depends on the database's row order.
        usort($eligible, fn (array $a, array $b) => $a['user_id'] <=> $b['user_id']);
        $installer = $actionKey === 'installation' ? ($st->data['installer'] ?? null) : null;
        $userId = $installer !== null && in_array($installer, array_column($eligible, 'user_id'), true)
            ? (int) $installer : (int) $this->s->rng->pick($eligible)['user_id'];
        // Department inbox work is handed out by the department supervisor (production) or the branch manager.
        $assigner = in_array($actionKey, ['manufacturing', 'quality_check'], true) ? $this->s->users['sup_prod'] : $this->manager($st);
        app(T01AssignTask::class)->handle($assigner, $task, $userId, null);
    }

    private function doTask(Story $st, string $actionKey, string $by): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, $actionKey) ?? throw new \RuntimeException("no open task {$actionKey}");
        $actor = User::query()->findOrFail($task->assignee_id ?? $this->sales($st)->id);
        app(T05CompleteTask::class)->handle($actor, $task->refresh(), null);
    }

    private function sitePhotos(Story $st): void
    {
        $task = $this->openTask($this->order($st), 'site_photos');
        if ($task === null) {
            return;
        }
        $actor = User::query()->findOrFail($task->assignee_id ?? $this->sales($st)->id);
        $pool = array_merge(...array_values(Assets::KITCHENS));
        $file = $this->s->assets->image($this->s->rng->pick($pool));
        if ($file !== null) {
            app(AttachmentService::class)->store($actor, $task, $file, 'site_photos');
        }
        app(T05CompleteTask::class)->handle($actor, $task->refresh(), 'صور الموقع قبل التنفيذ.');
    }

    private function taxInvoice(Story $st): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, 'tax_invoice');
        if ($task === null) {
            return;
        }
        $actor = User::query()->findOrFail($task->assignee_id ?? $this->sales($st)->id);
        app(AttachmentService::class)->store($actor, $task, Assets::pdf('tax-invoice-'.$order->number.'.pdf', 'Tax invoice '.$order->number), 'tax_invoice');
        app(T05CompleteTask::class)->handle($actor, $task->refresh(), 'أُرفقت الفاتورة الضريبية الصادرة من النظام المحاسبي.');
    }

    private function doTaskNamed(Story $st, string $actionKey): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, $actionKey) ?? throw new \RuntimeException("no open task {$actionKey}");
        $actor = User::query()->findOrFail($task->assignee_id);
        if ($task->status === TaskStatus::Assigned) {
            app(T02StartTask::class)->handle($actor, $task);
            $task->refresh();
        }
        app(T05CompleteTask::class)->handle($actor, $task, 'تم الإنجاز حسب المواصفات المعتمدة.');
    }

    private function doTaskAfterDesign(Story $st): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, 'design');
        if ($task === null) {
            return;
        }
        $actor = User::query()->findOrFail($task->assignee_id);
        $pool = array_merge(...array_values(Assets::KITCHENS));
        $file = $this->s->assets->image($this->s->rng->pick($pool)) ?? Assets::pdf('design-render-'.$order->number.'.pdf', 'Render');
        app(AttachmentService::class)->store($actor, $task, $file, 'design');
        app(T05CompleteTask::class)->handle($actor, $task->refresh(), 'التصميم النهائي بعد ملاحظات العميل.');
    }

    private function bookVisit(Story $st, string $kind, float $inDays): void
    {
        $order = $this->order($st);
        $branch = $st->data['branch'];
        $techKeys = ['RUH' => ['inst_ruh', 'inst_ruh2'], 'JED' => ['inst_jed'], 'DMM' => ['inst_ruh']][$branch];
        $tech = $this->s->users[$this->s->rng->pick($techKeys)];
        $start = CarbonImmutable::now()->addMinutes((int) ($inDays * 1440))->setTime($this->s->rng->int(8, 15), 0);
        while (! in_array($start->dayOfWeek, self::WORK_DAYS, true)) {
            $start = $start->addDay();
        }
        $sales = $this->sales($st);
        $task = $kind === 'installation' ? $this->openTask($order, 'installation') : null;
        if ($kind === 'installation') {
            $st->data['installer'] = $tech->id;
        }
        $res = app(T00ProposeAppointment::class)->handle($sales, $order, [
            'task_id' => $task?->id,
            'kind' => $kind, 'starts_at' => $start->format('Y-m-d H:i').' Asia/Riyadh', 'ends_at' => $start->addHours(2)->format('Y-m-d H:i').' Asia/Riyadh',
            'address' => 'حي '.$this->s->people->district($branch).'، '.People::CITY[$branch].' — شارع '.$this->s->rng->int(5, 60),
            'member_ids' => [$tech->id],
        ]);
        $appointment = $res['appointment'];
        $appointment = app(T01SendToCustomer::class)->handle($sales, $appointment);
        if (in_array($kind, $st->data['unconfirmed'] ?? [], true)) {
            $st->data['appt_'.$kind] = $appointment->id;

            return;
        }
        $appointment = app(T02ConfirmAppointment::class)->handle($sales, Appointment::query()->with('order')->findOrFail($appointment->id), 'phone', 'تنسيق مع فنيين آخرين في اليوم نفسه');
        $st->data['appt_'.$kind] = $appointment->id;
        if ($kind === 'installation') {
            // ADR-0036: the installer works the visit in the field app (arrive, photos, signature, finish).
            $this->s->installations->schedule($st, $appointment->id, $tech, $start);
        } elseif ($start->lessThanOrEqualTo($this->s->end)) {
            $this->s->timeline->at($start->addHours(3), $st, 'visit-'.$kind, function () use ($st, $kind, $tech) {
                $appt = Appointment::query()->findOrFail($st->int('appt_'.$kind));
                app(T05CompleteVisit::class)->handle($tech, $appt, $kind === 'measurement' ? 'تمت المعاينة وأُخذت القياسات.' : 'تم التركيب وسُلّم الموقع نظيفًا.', false);
            });
        }
    }

    private function doMeasure(Story $st): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, 'measure') ?? throw new \RuntimeException('no measure task');
        if ($task->status === TaskStatus::Unassigned) {
            $this->claimOrAssign($st, 'measure');
            $task->refresh();
        }
        $actor = User::query()->findOrFail($task->assignee_id);
        $rec = app(RecordMeasurement::class);
        $length = (float) $st->data['length'];
        foreach ([['الجدار الرئيسي', 'الطول', $length], ['الجدار الجانبي', 'الطول', round($length * 0.45, 2)], ['ارتفاع السقف', 'الارتفاع', 2.9 + $this->s->rng->int(0, 6) / 10]] as [$loc, $name, $v]) {
            $rec->handle($actor, $order, [
                'location' => $loc, 'name' => $name, 'value' => number_format($v, 3, '.', ''), 'unit' => 'm', 'kind' => 'final',
                'measured_on' => CarbonImmutable::now()->toDateString(),
            ], $task->refresh(), null);
        }
        app(T05CompleteTask::class)->handle($actor, $task->refresh(), 'تم أخذ القياسات النهائية.');
    }

    private function completeStage(Story $st, string $key): void
    {
        $order = $this->order($st);
        $stage = app(StageEngine::class)->activeStage($order);
        if ($stage === null || $stage->key !== $key) {
            throw new \RuntimeException('active stage is '.($stage === null ? 'none' : $stage->key).", wanted {$key}");
        }
        app(T04CompleteStage::class)->handle($this->manager($st), $stage, null);
    }

    private function uploadDesign(Story $st): void
    {
        $order = $this->order($st);
        $task = $this->openTask($order, 'design');
        $designer = User::query()->findOrFail($task->assignee_id ?? $this->sales($st)->id);
        // Stories that show the e-signed documents carry real design renders (JPEG): the quote PDF shows them.
        $renders = ($st->data['esign'] ?? null) !== null && $this->s->assets->enabled()
            ? [Assets::dir().'/design-1080721.jpg', Assets::dir().'/design-534151.jpg'] : [];
        $file = $renders === [] ? Assets::pdf('kitchen-design-'.$order->number.'.pdf', 'Design '.$order->number)
            : new UploadedFile($renders[0], 'kitchen-design-'.$order->number.'.jpg', 'image/jpeg', null, true);
        $doc = app(T00UploadDesign::class)->handle($designer, $order, $file, 'تصميم المطبخ — النسخة الأولى مع المناظير ثلاثية الأبعاد', [], null, $this->key());
        if (isset($renders[1])) {
            app(AttachmentService::class)->store($designer, $doc, new UploadedFile($renders[1], 'kitchen-island-'.$order->number.'.jpg', 'image/jpeg', null, true));
        }
        $sales = $this->sales($st);
        $doc = app(T01SendDocument::class)->handle($sales, $doc->refresh(), null, $doc->lock_version, $this->key());
        $st->data['design_doc'] = $doc->id;
    }

    private function approveDesign(Story $st): void
    {
        $order = $this->order($st);
        $doc = AgreementDocument::query()->findOrFail($st->int('design_doc'));
        if ($st->data['viaPortal']) {
            app(CustomerDecisions::class)->approveDocument($this->customerActor($order), $doc, (string) $doc->content_hash);

            return;
        }
        app(T02RecordExternalApproval::class)->handle($this->manager($st), $doc->refresh(), [
            'channel' => 'message', 'decided_on' => CarbonImmutable::now()->toDateString(), 'note' => 'وافق العميل على التصميم عبر واتساب',
            'content_hash' => (string) $doc->content_hash,
        ], Assets::pdf('design-approval.pdf', 'Approval'), $doc->lock_version, $this->key());
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteData(Story $st): array
    {
        $product = $this->s->catalog->products[$st->data['product']];
        $order = $this->order($st);
        $line = OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->first();
        $length = (float) $st->data['length'];
        $unit = round(($product->reference_price_minor / 100) * $st->data['mult'], 0);
        $lines = [
            ['order_line_id' => $line?->id, 'description' => $product->name->ar ?? 'مطبخ', 'quantity' => (string) $length, 'unit' => 'm', 'unit_price' => (string) $unit, 'commissionable' => true],
            ['description' => 'سطح عمل '.($length > 5 ? 'كوارتز' : 'رخام').' مع حوض مدمج', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => (string) (round($length * 650, 0)), 'commissionable' => true],
            ['description' => 'تركيب وتوصيل وتجهيز الموقع', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => (string) (900 + (int) ($length * 80)), 'commissionable' => false],
        ];

        return [
            'lines' => $lines,
            'discount' => $st->data['discount'] === '0' ? ['type' => 'none'] : ['type' => 'percent', 'value' => $st->data['discount']],
            'terms' => 'دفعة أولى 50% عند الاعتماد، والباقي عند اكتمال التركيب. مدة التنفيذ حوالي 30 يوم عمل من تاريخ الدفعة الأولى. ضمان سنتين على التصنيع.',
            'execution_days' => 30,
        ];
    }

    private function prepareQuote(Story $st): void
    {
        $order = $this->order($st);
        $sales = $this->sales($st);
        $quote = app(T00CreateQuote::class)->handle($sales, $order, $this->key());
        $quote = app(UpdateDocumentDraft::class)->handle($sales, $quote, $this->quoteData($st), $quote->lock_version, $this->key());
        $st->data['quote_doc'] = $quote->id;
        // Above the seller's own limit a manager decides first (the request is refused when it is within the limit).
        if ($st->data['discount'] !== '0') {
            try {
                $decision = app(T00RequestDiscount::class)->handle($sales, $quote->refresh(), 'العميل يقارن بعرض منافس ويرغب بإتمام الاتفاق هذا الأسبوع.', $quote->lock_version, $this->key());
                $st->data['decision'] = $decision->id;
            } catch (InvalidTransition) {
                // within the sales limit
            }
        }
    }

    private function sendQuote(Story $st): void
    {
        $quote = AgreementDocument::query()->findOrFail($st->int('quote_doc'));
        if (isset($st->data['decision'])) {
            $decision = DiscountDecision::query()->findOrFail($st->int('decision'));
            $approver = (int) $st->data['discount'] > 10 ? $this->s->users['gm'] : $this->manager($st);
            app(T01ApproveDiscount::class)->handle($approver, $decision, true, 'مقبول ضمن سياسة الخصم للمشاريع.', $decision->lock_version, $this->key());
            $quote->refresh();
        }
        // A quote waiting for the e-signature stays valid past the showcase's «today».
        $validity = ($st->data['esign'] ?? null) !== null ? 30 : null;
        $quote = app(T01SendDocument::class)->handle($this->sales($st), $quote->refresh(), $validity, $quote->lock_version, $this->key());
        if (($st->data['esign'] ?? null) !== null) {
            (new Signatures)->sendLink($this->sales($st), $quote);
        }
    }

    private function approveQuote(Story $st): void
    {
        $order = $this->order($st);
        $doc = AgreementDocument::query()->findOrFail($st->int('quote_doc'));
        if (($st->data['esign'] ?? null) === 'signed') {
            (new Signatures)->sign($doc, $this->signer($st, $order));
            $this->contract($st, $order);

            return;
        }
        if ($st->data['viaPortal']) {
            app(CustomerDecisions::class)->approveDocument($this->customerActor($order), $doc, (string) $doc->content_hash);

            return;
        }
        app(T02RecordExternalApproval::class)->handle($this->manager($st), $doc, [
            'channel' => $this->s->rng->pick(['message', 'phone', 'in_person']), 'decided_on' => CarbonImmutable::now()->toDateString(),
            'note' => 'اعتماد العرض والتوقيع', 'content_hash' => (string) $doc->content_hash,
        ], Assets::pdf('quote-approval.pdf', 'Signed quote'), $doc->lock_version, $this->key());
        $st->data['agreement'] = Agreement::query()->where('order_id', $order->id)->where('status', 'active')->value('id');
    }

    /**
     * The contract built from the agreement (Q-36 c), sent with a signing link and, for `contract: signed`, signed.
     */
    private function contract(Story $st, Order $order): void
    {
        $mode = $st->data['contract'] ?? null;
        if ($mode === null) {
            return;
        }
        $contract = app(T00PrepareContract::class)->handle($this->manager($st), $order->refresh(), null,
            'يلتزم الطرف الأول بضمان الهيكل والمفصلات خمس سنوات من تاريخ التركيب، ويلتزم الطرف الثاني بتجهيز الموقع قبل موعد التركيب.', $this->key());
        $contract = app(T01SendDocument::class)->handle($this->sales($st), $contract->refresh(), null, $contract->lock_version, $this->key());
        $signatures = new Signatures;
        $signatures->sendLink($this->sales($st), $contract);
        if ($mode === 'signed') {
            $signatures->sign($contract, $this->signer($st, $order));
        }
    }

    private function signer(Story $st, Order $order): string
    {
        return (string) ($st->data['signer'] ?? Customer::query()->whereKey($order->customer_id)->value('name'));
    }

    private function agreementTotal(Order $order): int
    {
        return (int) (Agreement::query()->where('order_id', $order->id)->where('status', 'active')->value('total_minor') ?? 0);
    }

    private function finance(Story $st): User
    {
        return $this->s->users['finance'];
    }

    private function pay(Story $st, string $amount, ?string $note = null): void
    {
        $order = $this->order($st);
        app(T01RecordPayment::class)->handle($this->finance($st), $order->refresh(), [
            'amount' => $amount, 'method' => 'transfer', 'paid_on' => CarbonImmutable::now()->toDateString(),
            'reference' => 'FT'.$this->s->rng->int(2600000000, 2699999999), 'note' => $note, 'acknowledge_similar' => true,
        ], Assets::pdf('transfer-'.$order->number.'.pdf', 'Transfer '.$amount), null, $this->key());
    }

    private function payDeposit(Story $st): void
    {
        $this->pay($st, (string) ($this->s->rng->int(10, 30) * 100), 'عربون عند اعتماد التصميم');
    }

    private function payFirst(Story $st): void
    {
        $order = $this->order($st);
        $total = $this->agreementTotal($order);
        $amount = Money::of((int) round($total * 0.5));
        if ($st->data['proofByCustomer']) {
            $customer = $this->customerActor($order);
            $proof = app(CustomerProofs::class)->submit($customer, $order, [
                'amount' => $amount->toInput(), 'method' => 'transfer', 'paid_on' => CarbonImmutable::now()->toDateString(),
                'reference' => 'FT'.$this->s->rng->int(2600000000, 2699999999),
            ], Assets::pdf('customer-receipt.pdf', 'Customer proof '.$order->number));
            $st->data['proof'] = $proof->id;

            return;
        }
        $this->pay($st, $amount->toInput(), 'الدفعة الأولى 50%');
    }

    private function confirmProof(Story $st): void
    {
        $proof = PaymentProof::query()->findOrFail($st->int('proof'));
        app(T01ConfirmProof::class)->handle($this->finance($st), $proof, [
            'amount' => $proof->claimed()->toInput(), 'paid_on' => $proof->paid_on->toDateString(),
        ], $proof->lock_version, $this->key());
    }

    private function payFinal(Story $st): void
    {
        $order = $this->order($st);
        $remaining = $this->remaining($order);
        if ($remaining > 0) {
            $this->pay($st, Money::of($remaining)->toInput(), 'الدفعة الأخيرة بعد التركيب');
        }
    }

    private function remaining(Order $order): int
    {
        $collected = (int) app(MoneyLedger::class)->collected($order->id)->jsonSerialize();

        return max(0, $this->agreementTotal($order) - $collected);
    }

    private function deliver(Story $st): void
    {
        $order = $this->order($st);
        $keeper = $this->s->users['inventory'];
        $lines = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->get() as $line) {
            $lines[] = ['order_line_id' => $line->id, 'quantity_milli' => Quantity::parse((string) $line->quantity)];
        }
        $note = app(T00CreateDraft::class)->handle($keeper, $order, $lines, null, $this->key());
        app(T01ConfirmDelivery::class)->handle($keeper, $note->refresh(), [
            'delivered_on' => CarbonImmutable::now()->toDateString(), 'receiver_name' => Customer::query()->findOrFail($order->customer_id)->name,
            'note' => 'سُلّم المطبخ مركبًا بعد المعاينة النهائية.',
        ], [Assets::pdf('delivery-'.$order->number.'.pdf', 'Delivered')], null, $this->key());
    }
}

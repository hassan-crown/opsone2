<?php

/**
 * Payment plans (ADR-0037): the plan rules (100%, one `rest` last, closed triggers, existing stages, flow gate
 * references), copying the lines into the order's dues at the agreement (later plan edits never reach it), the order's
 * own choice before and after the agreement, money integrity (amounts always add up to the agreement), the single
 * source of the first payment share (`payment_check` basis `due`), trigger evaluation with offsets and grace, and
 * authorization (P-104 / P-38) with audit.
 */

use App\Actions\Finance\ChoosePaymentPlan;
use App\Actions\Rules\ArchivePaymentPlan;
use App\Actions\Rules\SavePaymentPlan;
use App\Enums\DueStatus;
use App\Finance\CollectionConditions;
use App\Finance\DueCalendar;
use App\Finance\FinanceLock;
use App\Finance\OrderSchedule;
use App\Finance\Plans\PaymentPlans;
use App\Models\Agreements\AgreementDocument;
use App\Models\AuditEvent;
use App\Models\Delivery\DeliveryNote;
use App\Models\Finance\OrderDue;
use App\Models\Finance\OrderFinance;
use App\Models\Finance\PaymentPlan;
use App\Money\Money;
use App\Support\StaleRecord;
use App\Workflow\AppointmentBooking;
use App\Workflow\FlowCheck;
use App\Workflow\FlowTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/RulesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

/**
 * @return list<array{reference: string, amount: int, event: string, stage: string|null, offset: int, grace: int}>
 */
function planDues(int $orderId): array
{
    return array_values(OrderDue::query()->where('order_id', $orderId)->whereNull('removed_at')->orderBy('position')->get()
        ->map(fn (OrderDue $d) => ['reference' => $d->reference, 'amount' => $d->amount_minor, 'event' => $d->due_event->value, 'stage' => $d->stage_key, 'offset' => $d->offset_days, 'grace' => $d->grace_days])->all());
}

it('refuses plans that do not make 100%, misplace or repeat the rest, or name unknown triggers and stages', function () {
    $owner = $this->f->owner;
    $refused = fn (array $lines) => expect(fn () => savePlan($owner, 'bad', $lines))->toThrow(ValidationException::class);

    $refused([planLine('first', 'percent', '60'), planLine('final', 'percent', '30')]);                  // 90%
    $refused([planLine('first', 'percent', '60'), planLine('final', 'percent', '50')]);                  // 110%
    $refused([planLine('first', 'rest', null), planLine('final', 'percent', '50')]);                     // rest not last
    $refused([planLine('first', 'percent', '50'), planLine('mid', 'rest', null), planLine('final', 'rest', null)]); // two rests
    $refused([planLine('first', 'amount', '1000'), planLine('final', 'percent', '100')]);                 // amount without a rest
    $refused([planLine('first', 'percent', '100'), planLine('final', 'rest', null)]);                     // nothing left for the rest
    $refused([planLine('first', 'percent', '50', 'whenever'), planLine('final', 'rest', null)]);          // unknown trigger
    $refused([planLine('first', 'percent', '50', 'date'), planLine('final', 'rest', null)]);              // a date belongs to one order
    $refused([planLine('first', 'percent', '50', 'stage.started', ['stage_key' => 'moon']), planLine('final', 'rest', null)]);
    $refused([planLine('first', 'percent', '50', extra: ['grace_days' => 91]), planLine('final', 'rest', null)]);
    // The published flow waits for «first» and «final»: a plan without «final» would make that gate ask for everything.
    $refused([planLine('first', 'percent', '50'), planLine('second', 'rest', null)]);
    $refused(array_map(fn (int $i) => planLine($i === 0 ? 'first' : ($i === 12 ? 'final' : "p{$i}"), 'amount', '10'), range(0, 12)));

    $plan = savePlan($owner, 'ok', [planLine('first', 'percent', '33.33'), planLine('mid', 'percent', '33.33'), planLine('final', 'percent', '33.34')]);
    expect(array_column($plan->lines, 'value'))->toBe([3333, 3333, 3334])
        ->and($plan->is_default)->toBeTrue(); // the first plan becomes the default
    expect(fn () => savePlan($owner, 'ok', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)]))->toThrow(ValidationException::class); // key taken
});

it('copies the plan lines into the dues at the agreement; later plan edits never change the order', function () {
    $plan = installmentPlan($this->f->owner);
    finAgree($this->f, '10000.01');

    expect(planDues($this->f->order->id))->toBe([
        ['reference' => 'first', 'amount' => 400_000, 'event' => 'agreement', 'stage' => null, 'offset' => 0, 'grace' => 0],
        ['reference' => 'second', 'amount' => 400_000, 'event' => 'stage.started', 'stage' => 'install', 'offset' => 0, 'grace' => 0],
        ['reference' => 'final', 'amount' => 200_001, 'event' => 'delivery.completed', 'stage' => null, 'offset' => 15, 'grace' => 3],
    ]);
    $head = OrderFinance::query()->where('order_id', $this->f->order->id)->sole();
    expect($head->payment_plan_id)->toBe($plan->id)->and($head->payment_plan_key)->toBe('forty_forty');

    // The owner changes the plan to 50/50: the order keeps its copied 40/40/20.
    savePlan($this->f->owner, 'forty_forty', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)], true, $plan->refresh());
    expect(array_column(planDues($this->f->order->id), 'amount'))->toBe([400_000, 400_000, 200_001]);

    // An agreement change re-prices from the same copied lines.
    $change = agrChange($this->f->owner, $this->f->order, [finLine('مطبخ', '20000')]);
    agrApplyChange($this->f->owner, $change);
    expect(array_column(planDues($this->f->order->id), 'amount'))->toBe([800_000, 800_000, 400_000]);
});

it('keeps every halala: the dues always add up to the agreement, whatever the shares', function () {
    savePlan($this->f->owner, 'thirds', [planLine('first', 'percent', '33.33'), planLine('mid', 'percent', '33.33'), planLine('final', 'percent', '33.34')], true);
    finAgree($this->f, '10000.01');
    $amounts = array_column(planDues($this->f->order->id), 'amount');
    expect(array_sum($amounts))->toBe(1_000_001)->and($amounts)->toBe([333_300, 333_300, 333_401]);

    expect(array_sum(OrderSchedule::preview(PaymentPlans::TEMPLATES['forty_forty_twenty']['lines'], Money::of(777_777))))->toBe(777_777);
});

it('lets the order choose its plan before the agreement and replace its schedule from a plan after it (P-38)', function () {
    savePlan($this->f->owner, 'half_half', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)], true);
    $installments = installmentPlan($this->f->owner, false);
    $choose = app(ChoosePaymentPlan::class);

    expect(fn () => $choose->handle($this->f->executor, $this->f->order, $installments->id, null, agrKey()))->toThrow(AuthorizationException::class);
    $choose->handle($this->f->finance, $this->f->order, $installments->id, null, agrKey());
    expect(AuditEvent::query()->where('action', 'payment_plan.chosen')->count())->toBe(1);

    finAgree($this->f, '20000');
    expect(array_column(planDues($this->f->order->id), 'reference'))->toBe(['first', 'second', 'final']);

    // After the agreement: the schedule is replaced from the default plan.
    $default = PaymentPlan::query()->where('key', 'half_half')->sole();
    $choose->handle($this->f->finance, $this->f->order->refresh(), $default->id, FinanceLock::versionOf($this->f->order), agrKey());
    expect(array_column(planDues($this->f->order->id), 'amount'))->toBe([1_000_000, 1_000_000])
        ->and(OrderFinance::query()->where('order_id', $this->f->order->id)->value('payment_plan_key'))->toBe('half_half');

    // A paid due never drops below its money: 40% for «first» is refused once 50% sits on it.
    finPay($this->f->finance, $this->f->order, '10000');
    expect(fn () => $choose->handle($this->f->finance, $this->f->order->refresh(), $installments->id, FinanceLock::versionOf($this->f->order), agrKey()))
        ->toThrow(ValidationException::class);
    expect(array_column(planDues($this->f->order->id), 'amount'))->toBe([1_000_000, 1_000_000]);

    // An archived plan is no longer offered.
    app(ArchivePaymentPlan::class)->handle($this->f->owner, $installments);
    expect(fn () => $choose->handle($this->f->finance, $this->f->order->refresh(), $installments->id, null, agrKey()))->toThrow(ValidationException::class);
});

it('takes the first payment share from the plan only: the flow gate waits for the due, not for its own percentage', function () {
    savePlan($this->f->owner, 'thirty', [planLine('first', 'percent', '30'), planLine('final', 'rest', null)], true);
    finAgree($this->f, '20000');

    $first = collect(CollectionConditions::overview($this->f->order))->firstWhere('reference', 'first');
    expect($first['basis'])->toBe('due')->and($first['threshold_minor'])->toBe(600_000)->and($first['met'])->toBeFalse();
    finPay($this->f->finance, $this->f->order, '6000');
    expect(collect(CollectionConditions::overview($this->f->order))->firstWhere('reference', 'first')['met'])->toBeTrue();

    // The final check waits for the whole schedule.
    expect(collect(CollectionConditions::overview($this->f->order))->firstWhere('reference', 'final')['threshold_minor'])->toBe(2_000_000);

    // The flow editor no longer offers a percentage: it would duplicate the plan.
    $definition = FlowTemplates::definition(FlowTemplates::DEFAULT);
    $definition['stages'][2]['actions'][3]['inputs'] = ['reference' => 'first', 'basis' => 'percent', 'value' => 50];
    expect(collect(app(FlowCheck::class)->run($definition)->issues)->pluck('code')->all())->toContain('invalid_input');
});

it('evaluates each trigger with its offset days, and grace days only delay «overdue»', function () {
    installmentPlan($this->f->owner);
    finAgree($this->f, '10000');
    $order = $this->f->order;
    $dues = fn () => OrderDue::query()->where('order_id', $order->id)->orderBy('position')->get();
    $today = AppointmentBooking::today();

    $dates = DueCalendar::dates($order, $dues());
    expect(array_values($dates))->toBe([$today, null, null]); // installation not started, nothing delivered

    // Stage started: the second due falls due that day.
    OrderDue::query()->where('order_id', $order->id)->where('reference', 'second')->update(['stage_key' => 'agreement']);
    finToAgreementStage($this->f);
    expect(array_values(DueCalendar::dates($order, $dues()))[1])->toBe($today);

    // Completed delivery + 15 days; grace 3 keeps it «due» on days 16..18, «overdue» from day 19.
    $delivered = CarbonImmutable::parse($today)->subDays(17)->toDateString();
    DeliveryNote::query()->create(['order_id' => $order->id, 'status' => 'confirmed', 'delivered_on' => $delivered, 'completes_order' => true, 'number' => 'DN-T-1', 'receiver_name' => 'x']);
    $dueOn = array_values(DueCalendar::dates($order, $dues()))[2];
    expect($dueOn)->toBe(CarbonImmutable::parse($delivered)->addDays(15)->toDateString())
        ->and(DueCalendar::status(100, 0, $dueOn, $today, 3))->toBe(DueStatus::Due)
        ->and(DueCalendar::status(100, 0, $dueOn, CarbonImmutable::parse($today)->addDays(2)->toDateString(), 3))->toBe(DueStatus::Overdue)
        ->and(DueCalendar::status(100, 0, $dueOn, CarbonImmutable::parse($today)->addDays(1)->toDateString(), 0))->toBe(DueStatus::Overdue);
});

it('reads contract and design approvals as triggers', function () {
    savePlan($this->f->owner, 'docs', [
        planLine('first', 'percent', '50', 'contract.approved', ['offset_days' => 2]),
        planLine('final', 'rest', null, 'design.approved'),
    ], true);
    finAgree($this->f, '10000');
    $order = $this->f->order;
    $dues = OrderDue::query()->where('order_id', $order->id)->orderBy('position')->get();
    expect(array_values(DueCalendar::dates($order, $dues)))->toBe([null, null]);

    $day = CarbonImmutable::parse('2026-10-01 09:00', AppointmentBooking::timezone());
    foreach (['contract' => 'CON-T-1', 'design' => 'DSN-T-1'] as $kind => $number) {
        AgreementDocument::query()->create(['order_id' => $order->id, 'kind' => $kind, 'status' => 'approved', 'number' => $number, 'content' => [], 'decided_at' => $day]);
    }
    expect(array_values(DueCalendar::dates($order, $dues)))->toBe(['2026-10-03', '2026-10-01']);
});

it('moves the default mark, never archives the default and audits every change', function () {
    $a = savePlan($this->f->owner, 'a_plan', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)]);
    $b = savePlan($this->f->owner, 'b_plan', [planLine('first', 'percent', '20'), planLine('final', 'rest', null)], true);
    expect($a->refresh()->is_default)->toBeFalse()->and($b->is_default)->toBeTrue();
    expect(fn () => app(ArchivePaymentPlan::class)->handle($this->f->owner, $b))->toThrow(ValidationException::class);
    app(ArchivePaymentPlan::class)->handle($this->f->owner, $a);
    expect(PaymentPlan::query()->active()->pluck('key')->all())->toBe(['b_plan'])
        ->and(AuditEvent::query()->where('action', 'payment_plan.saved')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'payment_plan.archived')->count())->toBe(1);

    // A stale edit is refused.
    expect(fn () => app(SavePaymentPlan::class)->handle($this->f->owner, $b, [
        'key' => 'b_plan', 'name' => ['ar' => 'ب'], 'lines' => [planLine('first', 'percent', '20'), planLine('final', 'rest', null)],
    ], $b->lock_version + 5))->toThrow(StaleRecord::class);
});

it('serves the plans page to P-104 and P-02 holders and refuses changes without P-104', function () {
    $lines = [planLine('first', 'percent', '50'), planLine('final', 'rest', null)];
    $this->actingAs($this->f->owner)->get('/settings/payment-plans')->assertOk()
        ->assertInertia(fn ($page) => $page->component('rules/payment-plans')->where('canEdit', true)->has('templates', 4)->where('gateReferences', ['first', 'final']));
    $this->actingAs($this->f->manager)->get('/settings/payment-plans')->assertOk()->assertInertia(fn ($page) => $page->where('canEdit', false));
    $this->actingAs($this->f->sales)->get('/settings/payment-plans')->assertForbidden();

    $this->actingAs($this->f->manager)->post('/settings/payment-plans', ['key' => 'm', 'name' => ['ar' => 'م'], 'lines' => $lines])->assertForbidden();
    $this->actingAs($this->f->owner)->post('/settings/payment-plans', ['key' => 'owner_plan', 'name' => ['ar' => 'خطة'], 'lines' => $lines])
        ->assertRedirect('/settings/payment-plans')->assertSessionHasNoErrors();
    $this->actingAs($this->f->owner)->post('/settings/payment-plans', ['key' => 'bad', 'name' => ['ar' => 'خطة'], 'lines' => [planLine('first', 'percent', '40')]])
        ->assertSessionHasErrors('lines');
    expect(PaymentPlan::query()->pluck('key')->all())->toBe(['owner_plan']);
});

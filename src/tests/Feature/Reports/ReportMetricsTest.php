<?php

/**
 * Report numbers against hand-computed fixtures (SHR-12, SHR-15, ADR-0026, docs/p9-metrics.md). Every amount is in
 * halalas; prices are VAT-inclusive at 15%, so a gross of 1,845,000 carries VAT 240,652 and net 1,604,348.
 */

use App\Finance\MoneyLedger;
use App\Models\Appointment;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\PurchaseReference;
use App\Models\Task;
use App\Reports\MetricCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReportsTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('sales: agreed value is the active agreement split into net and VAT, per order and across branches', function () {
    finAgree($this->f, '18450');
    repOrderInB($this->f, '1000');

    $m = repMetrics(repReport($this->f->owner, 'sales'));
    expect($m['sales.orders_created'])->toBe(2)
        ->and($m['sales.agreements_approved'])->toBe(2)
        ->and($m['sales.agreed_gross_minor'])->toBe(1_945_000)
        ->and($m['sales.agreed_net_minor'])->toBe(1_691_305)
        ->and($m['sales.agreed_vat_minor'])->toBe(253_695)
        ->and($m['sales.open_orders'])->toBe(2);

    // A branch-A manager sees only branch A: one order, 1,845,000 gross.
    $a = repMetrics(repReport($this->f->manager, 'sales'));
    expect($a['sales.orders_created'])->toBe(1)->and($a['sales.agreed_gross_minor'])->toBe(1_845_000);

    // The owner's branch filter narrows the same way.
    $b = repMetrics(repReport($this->f->owner, 'sales', ['branch_ids' => [$this->f->org->b->id]]));
    expect($b['sales.orders_created'])->toBe(1)->and($b['sales.agreed_gross_minor'])->toBe(100_000);
});

it('sales: a purchase reference is never a third value — orders sharing one are counted once each', function () {
    finAgree($this->f, '18450');
    $other = repOrderInB($this->f, '1000');
    $ref = PurchaseReference::query()->create(['number' => 'PR-1', 'customer_id' => $this->f->inA->id]);
    $this->f->order->forceFill(['purchase_reference_id' => $ref->id])->saveQuietly();
    $other->forceFill(['purchase_reference_id' => $ref->id])->saveQuietly();

    expect(repMetrics(repReport($this->f->owner, 'sales'))['sales.agreed_gross_minor'])->toBe(1_945_000);
});

it('sales: a partial cancellation lowers the agreed value to the re-priced agreement, a full cancellation leaves the sale out (SHR-15)', function () {
    agrAgreed($this->f, agrQuoteData()['lines']); // 91,500 inclusive
    expect(repMetrics(repReport($this->f->owner, 'sales'))['sales.agreed_gross_minor'])->toBe(9_150_000);

    cnCancel($this->f->owner, $this->f->order, ['scope' => 'partial', 'lines' => [['ref' => 'a0', 'quantity' => '2']]]);
    $m = repMetrics(repReport($this->f->owner, 'sales'));
    expect($m['sales.agreed_gross_minor'])->toBe(6_350_000)->and($m['sales.cancelled'])->toBe(0);

    $returns = repMetrics(repReport($this->f->owner, 'returns'));
    expect($returns['cancellations.confirmed'])->toBe(1)->and($returns['cancellations.partial'])->toBe(1);

    cnCancel($this->f->owner, $this->f->order->refresh(), ['scope' => 'full']);
    $after = repMetrics(repReport($this->f->owner, 'sales'));
    expect($after['sales.agreed_gross_minor'])->toBe(0)->and($after['sales.cancelled'])->toBe(1)->and($after['sales.open_orders'])->toBe(0);
});

it('collection: collected, correction, refund and net come from the ledger by journal kind; corrections never vanish', function () {
    finAgree($this->f, '10000');
    $first = finPay($this->f->finance, $this->f->order, '5000');
    $second = finPay($this->f->finance, $this->f->order, '3000');
    setCorrect($this->f->finance, $this->f->order, $first, ['kind' => 'void']);
    $refund = setRefund($this->f->finance, $this->f->order, $second, '100');
    setPaid($this->f->finance, $refund);

    $m = repMetrics(repReport($this->f->owner, 'collection'));
    expect($m['collection.collected_minor'])->toBe(800_000)
        ->and($m['collection.corrections_minor'])->toBe(-500_000)
        ->and($m['collection.refunded_minor'])->toBe(-10_000)
        ->and($m['collection.transfers_minor'])->toBe(0)
        ->and($m['collection.net_minor'])->toBe(290_000)
        // The ledger agrees with the report: what the order now holds.
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(290_000);
});

it('collection: aging buckets by derived due date from the unpaid remainder', function () {
    finAgree($this->f, '10000');
    repDueOn($this->f->order, 1, 3);
    repDueOn($this->f->order, 2, 40);

    $report = repReport($this->f->owner, 'collection');
    $m = repMetrics($report);
    expect($m['collection.overdue_minor'])->toBe(1_000_000)->and($m['collection.open_minor'])->toBe(1_000_000);
    $buckets = collect($report['charts'])->firstWhere('key', 'aging')['points'];
    expect(collect($buckets)->pluck('value', 'label')->all())->toBe(['not_overdue' => 0, 'd1_7' => 500_000, 'd8_30' => 0, 'd30_plus' => 500_000])
        ->and(array_column($report['table']['rows'], 'age_days'))->toBe([40, 3]);

    // The P6b screen reads the same numbers.
    $screen = $this->actingAs($this->f->owner)->getJson('/reports/collection')->json();
    expect($screen['overdue_total_minor'])->toBe(1_000_000)->and($screen['buckets']['d30_plus']['amount_minor'])->toBe(500_000);
});

it('refunds: requested, executed and open amounts from the refund records', function () {
    finAgree($this->f, '10000');
    $payment = finPay($this->f->finance, $this->f->order, '5000');
    setPaid($this->f->finance, setRefund($this->f->finance, $this->f->order, $payment, '100'));
    setRefund($this->f->finance, $this->f->order, $payment, '200');

    $report = repReport($this->f->owner, 'refunds');
    $m = repMetrics($report);
    expect($m['refunds.requested_count'])->toBe(2)->and($m['refunds.executed_count'])->toBe(1)
        ->and($m['refunds.executed_minor'])->toBe(10_000)->and($m['refunds.open_minor'])->toBe(20_000)
        ->and($report['table']['rows'])->toHaveCount(2);
});

it('workflow: completed tasks with their average time, overdue only with a due date', function () {
    finAgree($this->f, '10000');
    $tasks = Task::query()->where('order_id', $this->f->order->id)->orderBy('id')->get();
    expect($tasks->count())->toBeGreaterThanOrEqual(2);
    Task::query()->where('order_id', $this->f->order->id)->update(['due_at' => null]);
    $tasks[0]->forceFill(['status' => 'completed', 'created_at' => now()->subHours(4), 'completed_at' => now()])->saveQuietly();
    $tasks[1]->forceFill(['status' => 'assigned', 'due_at' => now()->subDays(2)])->saveQuietly();

    $m = repMetrics(repReport($this->f->owner, 'workflow'));
    expect($m['workflow.tasks_completed'])->toBe(1)->and($m['workflow.avg_completion_hours'])->toEqual(4)->and($m['workflow.tasks_overdue'])->toBe(1);
});

it('appointments: scheduled excludes cancelled; done and no-show are counted apart', function () {
    $o = $this->f->order;
    $make = fn (string $status, bool $noShow = false) => Appointment::query()->create([
        'order_id' => $o->id, 'kind' => 'measurement', 'status' => $status, 'starts_at' => now()->subHour(), 'ends_at' => now(), 'address' => 'الرياض', 'no_show' => $noShow,
    ]);
    $make('done');
    $make('confirmed', true);
    $make('cancelled');

    $m = repMetrics(repReport($this->f->owner, 'appointments'));
    expect($m['appointments.scheduled'])->toBe(2)->and($m['appointments.done'])->toBe(1)
        ->and($m['appointments.no_show'])->toBe(1)->and($m['appointments.cancelled'])->toBe(1);
});

it('procurement: placed orders, net and gross, remaining quantity and late orders; cost needs P-82', function () {
    $po = cnPurchaseFor($this->f->order);
    $po->forceFill(['ordered_at' => now()])->save();
    $late = cnPurchaseFor($this->f->order);
    $late->forceFill(['ordered_at' => now(), 'expected_on' => now()->subDays(3)->toDateString()])->save();
    PurchaseOrderLine::query()->where('purchase_order_id', $late->id)->update(['accepted_milli' => 4000]);

    $m = repMetrics(repReport($this->f->owner, 'procurement'));
    expect($m['procurement.orders_placed'])->toBe(2)->and($m['procurement.ordered_net_minor'])->toBe(200_000)
        ->and($m['procurement.ordered_gross_minor'])->toBe(230_000)
        ->and($m['procurement.remaining_milli'])->toBe(10_000 + 6_000)->and($m['procurement.late_orders'])->toBe(1);
});

it('every metric a report emits is defined once in the catalogue', function () {
    finAgree($this->f, '10000');
    foreach (['sales', 'collection', 'refunds', 'commissions', 'workflow', 'appointments', 'inventory', 'procurement', 'returns', 'store'] as $key) {
        foreach (repReport($this->f->owner, $key, status: in_array($key, ['commissions', 'inventory'], true) ? 200 : 200)['metrics'] ?? [] as $metric) {
            expect(MetricCatalogue::has($metric['key']))->toBeTrue();
        }
    }
    expect(fn () => MetricCatalogue::get('nope.metric'))->toThrow(InvalidArgumentException::class);
});

it('documents every catalogued metric in docs/p9-metrics.md', function () {
    $path = base_path('../docs/p9-metrics.md');
    if (! is_file($path)) {
        $this->markTestSkipped('docs/ is not mounted in this container.');
    }
    $doc = (string) file_get_contents($path);
    foreach (array_keys(MetricCatalogue::DEFINITIONS) as $key) {
        expect($doc)->toContain('`'.$key.'`');
    }
});

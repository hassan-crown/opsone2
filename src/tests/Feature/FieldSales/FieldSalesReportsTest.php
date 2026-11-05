<?php

/**
 * Field sales reports (ADR-0041): sales by channel (orders, booked, collected, field share) and performance per rep
 * (dispatched leads, visits, conversions, orders, booked, commission), each from its own source, scoped by P-60 and
 * with amounts only over P-37 / commission over P-94.
 */

use App\FieldSales\FieldDispatch;
use App\FieldSales\SalesVisits;
use App\Models\Agreements\Agreement;
use App\Models\Commissions\CommissionEntry;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/FieldSalesTestHelpers.php';
require_once __DIR__.'/../Reports/ReportsTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    Queue::fake();
    $f = $this->f = fieldSalesFixture();
    comPolicy($f->owner, 500);

    // Showroom: the fixture's order, agreed at 5,000.00.
    agrAgreed($f, [finLine('مطبخ', '5000')]);

    // Field: two leads dispatched to the rep in A, one converted, visited and agreed at 2,000.00, 1,000.00 collected.
    $won = fieldLead($f->owner, $f->org->a, 'سالم أحمد');
    $lost = fieldLead($f->owner, $f->org->a, 'فهد محمد');
    app(FieldDispatch::class)->assignLead($f->supervisor, $won, $f->rep->id);
    app(FieldDispatch::class)->assignLead($f->supervisor, $lost, $f->rep->id);
    $this->actingAs($f->rep)->post("/field/leads/{$won->id}/convert")->assertSessionHasNoErrors();
    $this->fieldOrder = Order::query()->findOrFail($won->refresh()->converted_order_id);
    $visit = fieldVisit($f->owner, $this->fieldOrder, [$f->rep]);
    $sales = app(SalesVisits::class);
    $sales->finish($f->rep, $sales->start($f->rep, $visit), 'تم الاتفاق');
    agrApprove($f->owner, agrSend($f->rep, agrQuote($f->rep, $this->fieldOrder, agrQuoteData(lines: [finLine('خزائن', '2000')]))));
    finPay($f->owner, $this->fieldOrder, '1000');

    // Branch B: one lead dispatched to the rep there.
    app(FieldDispatch::class)->assignLead($f->supervisorB, fieldLead($f->owner, $f->org->b), $f->repB->id);

    $this->showroomTotal = (int) Agreement::query()->where('order_id', $f->order->id)->where('status', 'active')->value('total_minor');
    $this->fieldTotal = (int) Agreement::query()->where('order_id', $this->fieldOrder->id)->where('status', 'active')->value('total_minor');
});

it('splits orders, booked sales and collections by channel and gives the field share', function () {
    $report = repReport($this->f->owner, 'channels');
    $rows = collect($report['table']['rows'])->keyBy('channel');
    $metrics = repMetrics($report);

    expect($this->showroomTotal)->toBe(500_000)->and($this->fieldTotal)->toBe(200_000)
        ->and($rows['showroom'])->toMatchArray(['orders' => 1, 'booked_minor' => 500_000, 'collected_minor' => 0])
        ->and($rows['field_sales'])->toMatchArray(['orders' => 1, 'booked_minor' => 200_000, 'collected_minor' => 100_000])
        ->and($rows['online_store'])->toMatchArray(['orders' => 0, 'booked_minor' => 0, 'collected_minor' => 0])
        ->and($metrics['channels.orders_created'])->toBe(2)
        ->and($metrics['channels.booked_minor'])->toBe(700_000)
        ->and($metrics['channels.collected_minor'])->toBe(100_000)
        ->and($metrics['channels.field_sales_share_percent'])->toEqual(28.6);
});

it('reports each rep\'s leads, visits, conversions, orders, booked sales and commission', function () {
    $report = repReport($this->f->owner, 'field_sales');
    $rows = collect($report['table']['rows'])->keyBy('member');
    $metrics = repMetrics($report);
    $commission = (int) CommissionEntry::query()->where('order_id', $this->fieldOrder->id)->where('user_id', $this->f->rep->id)->sum('amount_minor');

    expect($commission)->toBeGreaterThan(0)
        ->and($rows[$this->f->rep->name])->toMatchArray([
            'leads' => 2, 'visits' => 1, 'conversions' => 1, 'conversion_percent' => 50, 'orders' => 1,
            'booked_minor' => 200_000, 'commission_minor' => $commission,
        ])
        ->and($rows[$this->f->repB->name])->toMatchArray(['leads' => 1, 'visits' => 0, 'conversions' => 0, 'orders' => 0, 'booked_minor' => 0])
        ->and($metrics)->toMatchArray([
            'field_sales.leads_assigned' => 3, 'field_sales.visits_done' => 1, 'field_sales.conversions' => 1,
            'field_sales.orders' => 1, 'field_sales.booked_minor' => 200_000, 'field_sales.commission_minor' => $commission,
        ])
        ->and($metrics['field_sales.conversion_percent'])->toEqual(33.3);
});

it('keeps to the viewer\'s branches and hides amounts and commission without their grants', function () {
    $managerA = $this->f->manager;
    $metrics = repMetrics(repReport($managerA, 'field_sales'));
    expect($metrics['field_sales.leads_assigned'])->toBe(2);

    $inventory = $this->f->org->member('inventory', [$this->f->org->a]);
    $report = repReport($inventory, 'field_sales');
    expect(repMinorKeys($report))->toBe([])
        ->and(array_keys(repMetrics($report)))->not->toContain('field_sales.booked_minor')
        ->and(repMinorKeys(repReport($inventory, 'channels')))->toBe([]);

    // Exports run the same definitions.
    $this->actingAs($this->f->owner)->postJson('/reports/exports', ['report' => 'field_sales'])->assertSuccessful();
});

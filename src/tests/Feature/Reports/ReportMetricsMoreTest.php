<?php

/**
 * Commissions, inventory valuation, returns and the store against hand-computed fixtures (SHR-12, SHR-15).
 */

use App\Models\Commissions\CommissionEntry;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReportsTestHelpers.php';
require_once __DIR__.'/../Commissions/CommissionTestHelpers.php';
require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';
require_once __DIR__.'/../Returns/ReturnTestHelpers.php';
require_once __DIR__.'/../Store/StoreTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('attachments'));

it('commissions: accrual is the append-only sum of signed differences — a reversed collection lowers it, nothing is erased', function () {
    $f = commissionFixture(500);
    $payment = finPay($f->finance, $f->order, '600');
    agrAgreed($f, [finLine('مطبخ', '1150'), finLine('رسوم نقل', '50', false)]);
    expect(comAccrued($f->order))->toBe(2_500);

    $m = repMetrics(repReport($f->owner, 'commissions'));
    expect($m['commissions.accrued_minor'])->toBe(2_500)->and($m['commissions.paid_minor'])->toBe(0)->and($m['commissions.balance_minor'])->toBe(2_500);

    comReverse($payment, $f->order);
    $after = repReport($f->owner, 'commissions');
    expect(repMetrics($after)['commissions.accrued_minor'])->toBe(0)
        ->and(CommissionEntry::query()->count())->toBe(2)
        ->and($after['table']['rows'][0]['accrued_minor'])->toBe(0);
});

it('commissions: refused without P-94 even for someone with P-60', function () {
    $f = commissionFixture(500);
    repReport($f->supervisor, 'commissions', status: 403);
});

it('inventory: valuation is on-hand × weighted average NET cost of accepted purchase lines; movements and scope follow the location', function () {
    $s = stockFixture();
    stockReceive($s->la, 'material', $s->material->id, '6');
    stockReceive($s->lc, 'material', $s->material->id, '4');

    $supplier = Supplier::query()->create(['name' => ['ar' => 'مورد', 'en' => 'Supplier']]);
    $po = PurchaseOrder::query()->create([
        'number' => 'PO-2026-000777', 'supplier_id' => $supplier->id, 'branch_id' => $s->f->org->a->id, 'status' => 'received', 'vat_rate_bp' => 1500,
        'prices_include_vat' => false, 'net_minor' => 100_000, 'vat_minor' => 15_000, 'gross_minor' => 115_000, 'ordered_at' => now(),
    ]);
    PurchaseOrderLine::query()->create([
        'purchase_order_id' => $po->id, 'line_no' => 1, 'item_type' => 'material', 'item_id' => $s->material->id, 'item_name' => ['ar' => 'قماش', 'en' => 'Fabric'],
        'unit' => 'meter', 'quantity_milli' => 10_000, 'unit_cost_minor' => 10_000, 'net_minor' => 100_000, 'vat_minor' => 15_000, 'gross_minor' => 115_000,
        'received_milli' => 10_000, 'accepted_milli' => 10_000,
    ]);

    // 10 m on hand overall at 100,000 / 10 m = 10,000 halalas per metre (VAT excluded) → 100,000.
    $owner = $s->f->org->owner;
    $m = repMetrics(repReport($owner, 'inventory'));
    expect($m['inventory.valuation_minor'])->toBe(100_000)->and($m['inventory.unvalued_items'])->toBe(0)
        ->and($m['inventory.received_milli'])->toBe(10_000)->and($m['inventory.issued_milli'])->toBe(0)->and($m['inventory.items_in_stock'])->toBe(2);

    $a = repMetrics(repReport($owner, 'inventory', ['branch_ids' => [$s->f->org->a->id]]));
    expect($a['inventory.valuation_minor'])->toBe(60_000)->and($a['inventory.received_milli'])->toBe(6_000);

    // An item never purchased is unvalued, not zero-valued.
    stockReceive($s->la, 'ready_variant', $s->f->variant->id, '3');
    expect(repMetrics(repReport($owner, 'inventory'))['inventory.unvalued_items'])->toBe(1);
});

it('returns: requested, accepted quantity and settlement value come from the return records', function () {
    $f = returnFixture(10, 5, 5);
    $return = rtRequest($f->keeper, $f->order, $f->line, '2');
    rtAccept($f->manager, $return, '2');
    $return->refresh();
    $unit = (int) $f->order->activeLines()->firstOrFail()->unit_price_minor;

    $m = repMetrics(repReport($f->owner, 'returns'));
    expect($m['returns.requested'])->toBe(1)->and($m['returns.accepted_milli'])->toBe(2_000)
        ->and($m['returns.refund_value_minor'])->toBe((int) $return->settlement_minor)->and((int) $return->settlement_minor)->toBe(2 * $unit);
});

it('store: checkouts created, paid and conversion; money only with P-37', function () {
    $s = storeFixture(5);
    $buyer = storeBuyer();
    storeStart($buyer, [$s->f->variant->id => 1]);

    $report = repReport($s->f->org->owner, 'store');
    $m = repMetrics($report);
    expect($m['store.checkouts'])->toBe(1)->and($m['store.paid'])->toBe(0)->and($m['store.conversion_percent'])->toEqual(0)->and($m['store.paid_total_minor'])->toBe(0)
        ->and($report['table']['rows'])->toHaveCount(1);
});

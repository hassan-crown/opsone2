<?php

/**
 * A purchase line may serve an order of the purchase's own branch only: a link to another branch's order would put
 * the purchase into that order's cancellation review and settlement follow-up, and would reveal which order ids exist.
 */

use App\Actions\StateMachines\M13PurchaseOrder\T00CreatePurchaseOrder;
use App\Models\Procurement\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';
require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

it('links a purchase line only to an order of the purchase branch', function () {
    Storage::fake('attachments');
    $d = deliveryFixture(10, 2);
    $org = $d->s->f->org;
    $line = fn (int $orderId) => ['id' => null, 'item_type' => 'ready_variant', 'item_id' => $d->s->f->variant->id, 'quantity_milli' => 1000, 'unit_cost_minor' => 100, 'order_id' => $orderId];
    $create = fn (int $branchId) => app(T00CreatePurchaseOrder::class)->handle($d->owner, [
        'supplier_id' => procSupplier()->id, 'branch_id' => $branchId, 'location_id' => null, 'expected_on' => null,
        'responsible_id' => null, 'notes' => null, 'lines' => [$line($d->order->id)],
    ], (string) Str::uuid());

    expect(fn () => $create($org->b->id))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::query()->count())->toBe(0);
    expect($create($org->a->id)->lines()->value('order_id'))->toBe($d->order->id);
});

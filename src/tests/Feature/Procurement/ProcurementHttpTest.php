<?php

/**
 * The procurement screens over HTTP: pages render for the right people only, writes carry the idempotency key, the
 * receive form uploads proofs, and the page props of a member without P-82 carry no cost at all.
 */

use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\Procurement\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = procFixture();
});

it('serves the supplier screens to P-45 holders and the list to buyers, not to others', function () {
    $this->actingAs($this->f->inventory)->get(route('suppliers.index'))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('suppliers/index')->where('canManage', true)->has('suppliers.data', 1));
    $this->get(route('suppliers.create'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('suppliers/form')->where('supplier', null));
    $this->get(route('suppliers.edit', $this->f->supplier))->assertOk();

    $this->actingAs($this->f->sales)->get(route('suppliers.index'))->assertForbidden();
    $this->get(route('suppliers.create'))->assertForbidden();
    $this->post(route('suppliers.store'), ['name' => ['ar' => 'x']])->assertForbidden();
});

it('creates and edits a supplier, validating the VAT number format', function () {
    $this->actingAs($this->f->inventory);
    $this->post(route('suppliers.store'), ['name' => ['ar' => 'مورد جديد', 'en' => 'New'], 'vat_number' => '123'])->assertSessionHasErrors('vat_number');

    $this->post(route('suppliers.store'), ['name' => ['ar' => 'مورد جديد', 'en' => 'New'], 'vat_number' => '3000 0000 0000 003', 'idempotency_key' => 'abcdefgh1'])
        ->assertRedirect(route('suppliers.index'));
    $supplier = Supplier::query()->where('vat_number', '300000000000003')->firstOrFail();

    $this->put(route('suppliers.update', $supplier), ['name' => ['ar' => 'مورد معدل'], 'vat_number' => '300000000000003', 'lock_version' => $supplier->lock_version])->assertRedirect();
    $this->put(route('suppliers.update', $supplier), ['name' => ['ar' => 'قديم'], 'vat_number' => '300000000000003', 'lock_version' => $supplier->lock_version])->assertSessionHasErrors('lock_version');
    $this->post(route('suppliers.disable', $supplier))->assertRedirect();
    expect($supplier->refresh()->isActive())->toBeFalse();
});

it('creates, confirms and receives an order through the screens; a repeated receive key changes nothing', function () {
    $this->actingAs($this->f->inventory);
    $this->post(route('purchases.store'), [
        'supplier_id' => $this->f->supplier->id, 'branch_id' => $this->f->org->a->id, 'location_id' => $this->f->location,
        'expected_on' => now()->addWeek()->toDateString(), 'responsible_id' => $this->f->inventory->id,
        'lines' => [['item_type' => 'ready_variant', 'item_id' => $this->f->variant->id, 'quantity' => '10', 'unit_cost' => '11.50']],
        'idempotency_key' => 'create-key-1',
    ])->assertRedirect();
    // Creating needs a key: a double click never makes a second purchase order.
    $this->post(route('purchases.store'), [
        'supplier_id' => $this->f->supplier->id, 'branch_id' => $this->f->org->a->id,
        'lines' => [['item_type' => 'ready_variant', 'item_id' => $this->f->variant->id, 'quantity' => '1']],
    ])->assertSessionHasErrors('idempotency_key');
    $po = PurchaseOrder::query()->firstOrFail();
    expect($po->gross_minor)->toBe(11_500);

    $this->post(route('purchases.confirm', $po), ['lock_version' => $po->lock_version])->assertRedirect(route('purchases.show', $po));
    $this->get(route('purchases.show', $po))->assertOk()->assertInertia(fn (Assert $p) => $p->component('purchases/show')
        ->where('purchase.status', 'ordered')->where('purchase.can.receive', true)->where('purchase.totals.gross_minor', 11_500));
    $this->get(route('purchases.receive.form', $po))->assertOk()->assertInertia(fn (Assert $p) => $p->component('purchases/receive'));

    $line = $po->lines()->firstOrFail();
    $body = [
        'location_id' => $this->f->location, 'received_on' => now()->toDateString(), 'reference' => 'DN-1', 'lock_version' => $po->refresh()->lock_version,
        'lines' => [['line_id' => $line->id, 'accepted' => '4', 'rejected' => '1', 'reject_reason' => 'مكسور']],
        'proofs' => [procPdf()],
    ];
    $this->post(route('purchases.receive', $po), $body, ['Idempotency-Key' => 'receive-key-1'])->assertRedirect(route('purchases.show', $po));
    $this->post(route('purchases.receive', $po), $body, ['Idempotency-Key' => 'receive-key-1'])->assertRedirect(route('purchases.show', $po));

    expect(PurchaseReceipt::query()->count())->toBe(1)
        ->and($this->f->stock->calls)->toHaveCount(1)
        ->and($this->f->stock->calls[0]['quantityMilli'])->toBe(4_000)
        ->and($line->refresh()->remainingMilli())->toBe(6_000);
    // Without a key a receive is refused.
    $this->post(route('purchases.receive', $po), [...$body, 'lock_version' => null, 'proofs' => []])->assertSessionHasErrors('idempotency_key');

    $this->post(route('purchases.cancel', $po), ['reason' => 'لم يعد مطلوبًا.'])->assertRedirect();
    expect($po->refresh()->status->value)->toBe('cancelled_remainder');
    $this->get(route('purchases.cancel.form', $po))->assertStatus(409);
});

it('never puts a cost in the props of a member without P-82', function () {
    procDeny($this->f->manager, 'P-82');
    $po = procOrdered($this->f, [procVariantLine($this->f, 10_000, 1150)]);

    $this->actingAs($this->f->manager)->get(route('purchases.show', $po))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('purchase.costs_visible', false)->missing('purchase.totals')->missing('purchase.lines.0.unit_cost_minor'));
    $this->get(route('purchases.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('orders.data.0.costs_visible', false)->missing('orders.data.0.gross_minor'));
    $this->get(route('purchases.create'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('canCost', false));
});

it('keeps out-of-scope members from an order and its screens', function () {
    $po = procOrdered($this->f);

    $this->actingAs($this->f->inventoryB);
    $this->get(route('purchases.show', $po))->assertForbidden();
    $this->get(route('purchases.receive.form', $po))->assertForbidden();
    $this->get(route('purchases.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('orders.data', 0));
    $this->actingAs($this->f->sales)->get(route('purchases.index'))->assertForbidden();
    $this->get(route('purchases.items'))->assertForbidden();
});

it('searches items for a line: materials and ready variants that are not disabled', function () {
    $this->actingAs($this->f->inventory)->getJson(route('purchases.items', ['q' => 'HND']))->assertOk()
        ->assertJsonPath('items.0.item_type', 'ready_variant')->assertJsonPath('items.0.unit', 'piece');
    $this->getJson(route('purchases.items'))->assertOk()->assertJsonCount(3, 'items');
});

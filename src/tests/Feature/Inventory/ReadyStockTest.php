<?php

/**
 * Ready goods stock (FUL-02, M-05-T01/T09, M-24): confirmation reserves every line atomically, a short line refuses
 * it, cancellation releases only what is not delivered (scenario 6), availability on the order page, the branch
 * transfer impact (COM-12) and the reservation numbers.
 */

use App\Actions\Inventory\IssueReadyReservation;
use App\Inventory\Quantity;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Support\LockingTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    $this->owner = $this->s->f->org->owner;
});

it('confirms a ready draft and reserves it once, with SR numbers (FUL-02)', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '5');
    $order = readyOrder($this->s, 3);
    $year = now()->year;

    $this->actingAs($this->s->f->sales)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id, 'lock_version' => $order->lock_version])
        ->assertOk()->assertJsonPath('order.status', 'confirmed')->assertJsonPath('stock.0.reserved', '3');

    expect(StockReservation::query()->pluck('number')->all())->toBe(["SR-{$year}-000001"])
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '5', 'reserved' => '3', 'available' => '2']);

    // Confirming twice is a transition from the wrong state; nothing is reserved again.
    $this->actingAs($this->s->f->sales)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertStatus(403);
    expect(StockReservation::query()->count())->toBe(1);
    stockJournalMatches();
});

it('refuses the whole confirmation when one line is short, and the draft never reserves', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '1');
    $order = readyOrder($this->s, 2);
    expect(StockReservation::query()->count())->toBe(0);

    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.'.$order->lines()->value('id'));

    expect($order->refresh()->status)->toBe('draft')
        ->and(StockReservation::query()->count())->toBe(0)
        ->and(StockMovement::query()->where('kind', 'reserve')->count())->toBe(0);
});

it('lets only one of two orders take the last unit (scenario 3, sequential)', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '1');
    $first = readyOrder($this->s, 1);
    $second = readyOrder($this->s, 1);

    $this->actingAs($this->owner)->postJson("/orders/{$first->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();
    $this->actingAs($this->owner)->postJson("/orders/{$second->id}/confirm", ['location_id' => $this->s->la->id])->assertUnprocessable();

    expect($second->refresh()->status)->toBe('draft')
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id)['available'])->toBe('0');
});

it('reserves only from locations of the order branch or facility-wide ones', function () {
    stockReceive($this->s->lc, 'ready_variant', $this->s->f->variant->id, '5');
    $order = readyOrder($this->s, 1);

    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->lc->id])
        ->assertUnprocessable()->assertJsonValidationErrors('location_id');
});

it('cancels the undelivered rest: delivered 2 of 5 stay, 3 are released, nothing returns (scenario 6)', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '5');
    $order = readyOrder($this->s, 5);
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();
    $reservation = StockReservation::query()->firstOrFail();

    // Stock side of a delivery of 2 (the delivery session's M-05-T08 calls this), twice with the same key.
    foreach ([1, 2] as $attempt) {
        LockingTransaction::run(fn () => app(IssueReadyReservation::class)->handle($this->owner, $order, $reservation, Quantity::parse('2'), 'delivery', 1, 'delivery-1:line'));
    }
    expect(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '3', 'reserved' => '3', 'available' => '0']);

    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/cancel-remainder", ['reason' => 'العميل ألغى الباقي'])
        ->assertOk()->assertJsonPath('order.status', 'delivered');

    expect($reservation->refresh()->status->value)->toBe('settled_mixed')
        ->and($reservation->issued_milli)->toBe(2000)
        ->and($reservation->released_milli)->toBe(3000)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '3', 'reserved' => '0', 'available' => '3'])
        ->and(StockMovement::query()->whereIn('kind', ['receipt', 'adjustment_in', 'transfer_in'])->count())->toBe(1);
    stockJournalMatches();
});

it('cancels a confirmed order with nothing delivered: released, cancelled, on hand unchanged', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '4');
    $order = readyOrder($this->s, 3);
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();

    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/cancel-remainder", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/cancel-remainder", ['reason' => 'عدول العميل'])
        ->assertOk()->assertJsonPath('order.status', 'cancelled');

    expect(StockReservation::query()->firstOrFail()->status->value)->toBe('released')
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '4', 'reserved' => '0', 'available' => '4']);
    stockJournalMatches();
});

it('shows availability on the ready order page before confirmation', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '1');
    stockReceive($this->s->main, 'ready_variant', $this->s->f->variant->id, '1');
    stockReceive($this->s->lc, 'ready_variant', $this->s->f->variant->id, '7');
    $order = readyOrder($this->s, 3);

    $this->actingAs($this->s->f->sales)->get("/orders/{$order->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('orders/show')
            ->where('stock.kind', 'ready')
            ->where('stock.lines.0.available', '2') // A1 + MAIN, never branch C
            ->where('stock.lines.0.short', '1')
            ->where('stock.can.confirm', true)
            ->has('stock.locations', 3));
});

it('reports real reserved stock in the branch transfer impact', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '2');
    $order = readyOrder($this->s, 2);
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();

    $impact = $this->actingAs($this->owner)->getJson("/orders/{$order->id}/branch-transfer?branch_id={$this->s->f->org->b->id}")->assertOk()->json('impact.reserved_stock');

    expect($impact['available'])->toBeTrue()
        ->and($impact['applicable'])->toBeTrue()
        ->and($impact['reserved_lines'])->toHaveCount(1)
        ->and($impact['outside_target'])->toBe(1);
});

it('lists open ready reservations for P-48 holders', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '2');
    $order = readyOrder($this->s, 1);
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();

    $this->actingAs($this->s->keeper)->getJson('/stock/ready')->assertOk()->assertJsonPath('reservations.0.order.number', $order->number);
    $outside = $this->s->f->org->member('inventory', [$this->s->f->org->c]);
    $this->actingAs($outside)->getJson('/stock/ready')->assertOk()->assertJsonCount(0, 'reservations');
});

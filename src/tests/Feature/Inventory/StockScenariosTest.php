<?php

/**
 * Stock parts of the cross-domain scenarios (project plan «توزيع السيناريوهات»): 1 — an order cancelled without an
 * agreement leaves no stock movement; 4 — a cancellation after a delivery keeps the delivery and frees only the rest,
 * with the order and reservation document numbers traceable in the journal.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Inventory\IssueReadyReservation;
use App\Inventory\Quantity;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\StateTransition;
use App\Support\LockingTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    $this->owner = $this->s->f->org->owner;
});

it('scenario 1: an order cancelled without agreement or confirmation writes no stock movement', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '5');
    $before = StockMovement::query()->count();
    $year = now()->year;

    $this->actingAs($this->s->f->sales)->postJson('/orders', customPayload($this->s->f->inA))->assertCreated();
    $custom = lastOrder();
    $draft = readyOrder($this->s, 2);
    expect($custom->number)->toBe("ORD-{$year}-00001")->and($draft->number)->toBe("ORD-{$year}-00002");

    $this->actingAs($this->owner)->postJson("/orders/{$draft->id}/cancel-remainder", ['reason' => 'لم يتم الاتفاق'])
        ->assertOk()->assertJsonPath('order.status', 'cancelled');

    expect(StockMovement::query()->count())->toBe($before)
        ->and(StockReservation::query()->count())->toBe(0)
        ->and(StockMovement::query()->whereIn('order_id', [$custom->id, $draft->id])->count())->toBe(0)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '5', 'reserved' => '0', 'available' => '5'])
        ->and(StateTransition::query()->where('subject_id', $draft->id)->where('transition', 'T09')->value('to_state'))->toBe('cancelled');
});

it('scenario 4: delivery recorded first stays; the cancellation frees only the undelivered part, traceable by numbers', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '3');
    $order = readyOrder($this->s, 2);
    $year = now()->year;
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();
    $reservation = StockReservation::query()->firstOrFail();
    expect($reservation->number)->toBe("SR-{$year}-000001");

    LockingTransaction::run(fn () => app(IssueReadyReservation::class)->handle($this->owner, $order, $reservation, Quantity::parse('1'), 'delivery', 9, 'delivery-9'));
    $this->actingAs($this->owner)->postJson("/orders/{$order->id}/cancel-remainder", ['reason' => 'إلغاء بعد تسليم جزئي'])->assertOk()->assertJsonPath('order.status', 'delivered');

    // A delivery arriving after the cancellation is refused: no second issue and no stock coming back.
    expect(fn () => LockingTransaction::run(fn () => app(IssueReadyReservation::class)->handle($this->owner, $order->refresh(), $reservation, Quantity::parse('1'), 'delivery', 10, 'delivery-10')))
        ->toThrow(InvalidTransition::class);

    $journal = StockMovement::query()->where('reservation_id', $reservation->id)->orderBy('id')->get(['kind', 'document_number', 'order_id', 'quantity_milli']);
    expect($journal->pluck('kind')->map(fn ($k) => $k->value)->all())->toBe(['reserve', 'issue_reserved', 'release'])
        ->and($journal->pluck('document_number')->unique()->values()->all())->toBe(["SR-{$year}-000001"])
        ->and($journal->pluck('order_id')->unique()->values()->all())->toBe([$order->id])
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '2', 'reserved' => '0', 'available' => '2']);
    stockJournalMatches();
});

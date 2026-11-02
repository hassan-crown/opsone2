<?php

/**
 * M-14-T03 correction of a confirmed delivery (ADR-0034, product owner's decision): the delivered quantity is corrected
 * with a reason; for ready goods the person chooses general stock or this customer's reservation. P-52 on the order and
 * P-49 on each location that moves; stock and audit stay consistent; the «first delivery» date does not move.
 */

use App\Access\Access;
use App\Access\Permission;
use App\Delivery\DeliveryCorrected;
use App\Delivery\DeliveryProgress;
use App\Enums\DueEvent;
use App\Finance\DueCalendar;
use App\Models\Delivery\DeliveryCorrection;
use App\Models\Finance\OrderDue;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\StateTransition;
use App\Orders\OrderClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->d = deliveryFixture(10, 5);
    $this->order = $this->d->order;
    $this->line = deliveryLineId($this->order);
    $this->note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '5']), data: ['delivered_on' => now()->subDays(3)->toDateString()]);
    $this->noteLine = $this->note->lines()->firstOrFail();
    $this->variant = $this->d->s->f->variant;
});

/**
 * @param  array<string, mixed>  $extra
 */
function correctDelivery(object $t, $user, string $quantity, ?string $destination, array $extra = [])
{
    return $t->actingAs($user)->postJson(route('deliveries.corrections.store', $t->note), [
        'lines' => [['line_id' => $t->noteLine->id, 'quantity' => $quantity]], 'destination' => $destination,
        'reason' => 'عدّ المستلم قطعتين ناقصتين', 'idempotency_key' => (string) Str::uuid(), ...$extra,
    ]);
}

it('takes quantity back into the customer\'s reservation: still owed, the order is partially delivered again', function () {
    Event::fake([DeliveryCorrected::class]);
    expect($this->order->refresh()->status)->toBe('delivered')
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '5', 'reserved' => '0', 'available' => '5']);

    correctDelivery($this, $this->d->keeper, '2', 'reservation')->assertCreated();

    $reservation = StockReservation::query()->where('order_id', $this->order->id)->firstOrFail();
    expect(stockBalance($this->d->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '7', 'reserved' => '2', 'available' => '5'])
        ->and($reservation->issued_milli)->toBe(3000)->and($reservation->status->value)->toBe('partially_issued')
        ->and(DeliveryProgress::deliveredByLine($this->order->id))->toBe([$this->line => 3000])
        ->and(DeliveryProgress::lines($this->order->refresh())[$this->line]['deliverable'])->toBe(2000)
        ->and($this->order->status)->toBe('partially_delivered')
        ->and(StockMovement::query()->where('kind', 'correction_reserve')->where('reservation_id', $reservation->id)->sum('quantity_milli'))->toEqual(2000)
        ->and(StateTransition::query()->where('machine', 'M-14')->where('transition', 'T03')->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T08')->where('to_state', 'partially_delivered')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'delivery.corrected')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'stock.correction_returned')->count())->toBe(1)
        ->and($this->note->refresh()->status->value)->toBe('confirmed')
        ->and($this->noteLine->refresh()->quantity_milli)->toBe(5000);
    stockJournalMatches();
    Event::assertDispatched(DeliveryCorrected::class, fn (DeliveryCorrected $e) => $e->orderId === $this->order->id);

    // Delivering the owed quantity again closes the gap through the normal path.
    deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']));
    expect($this->order->refresh()->status)->toBe('delivered')
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '5', 'reserved' => '0', 'available' => '5']);
    stockJournalMatches();
});

it('takes quantity back to the general stock: free again, no longer owed, the order stays delivered', function () {
    correctDelivery($this, $this->d->keeper, '2', 'stock')->assertCreated();

    $reservation = StockReservation::query()->where('order_id', $this->order->id)->firstOrFail();
    expect(stockBalance($this->d->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '7', 'reserved' => '0', 'available' => '7'])
        ->and($reservation->issued_milli)->toBe(3000)->and($reservation->released_milli)->toBe(2000)
        ->and($reservation->status->value)->toBe('settled_mixed')
        ->and(DeliveryProgress::deliveredByLine($this->order->id))->toBe([$this->line => 3000])
        ->and(DeliveryProgress::lines($this->order->refresh())[$this->line]['deliverable'])->toBe(0)
        ->and($this->order->status)->toBe('delivered')
        ->and(StockMovement::query()->where('kind', 'correction_in')->sum('quantity_milli'))->toEqual(2000)
        ->and(array_column(OrderClosure::blockers($this->order), 'key'))->not->toContain('delivery_remaining');
    stockJournalMatches();
});

it('keeps the «first delivery» date (the current due rule) and records the date it saw for a future rule engine', function () {
    $due = OrderDue::query()->create([
        'order_id' => $this->order->id, 'position' => 1, 'reference' => 'final', 'name' => ['ar' => 'عند التسليم', 'en' => 'On delivery'],
        'basis' => 'rest', 'amount_minor' => 5000, 'due_event' => DueEvent::Delivery,
    ]);
    $before = DueCalendar::dates($this->order->refresh(), collect([$due]))[$due->id];

    correctDelivery($this, $this->d->keeper, '5', 'reservation')->assertCreated();

    expect(DueCalendar::dates($this->order->refresh(), collect([$due]))[$due->id])->toBe($before)
        ->and($before)->toBe(now()->subDays(3)->toDateString())
        ->and(DeliveryCorrection::query()->firstOrFail()->first_delivery_on?->toDateString())->toBe($before);
});

it('needs P-52 on the order and P-49 on the stock location; refusals change nothing', function () {
    $org = $this->d->s->f->org;
    $sales = $this->d->s->f->sales;              // no P-52
    $manager = $org->member('manager', [$org->a]); // a manager: P-52 without P-49
    $before = stockBalance($this->d->s->la, 'ready_variant', $this->variant->id);
    expect(Access::can($manager, Permission::P52, $this->order))->toBeTrue()
        ->and(Access::can($manager, Permission::P49, $this->d->s->la))->toBeFalse();

    correctDelivery($this, $sales, '1', 'stock')->assertForbidden();
    correctDelivery($this, $manager, '1', 'stock')->assertForbidden();

    expect(DeliveryCorrection::query()->count())->toBe(0)
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant->id))->toBe($before)
        ->and(StockMovement::query()->whereIn('kind', ['correction_in', 'correction_reserve'])->count())->toBe(0)
        ->and($this->order->refresh()->status)->toBe('delivered');

    correctDelivery($this, $this->d->owner, '1', 'stock')->assertCreated();
});

it('validates the destination, the reason and the quantity, replays one key once and refuses a stale note', function () {
    correctDelivery($this, $this->d->keeper, '1', null)->assertUnprocessable()->assertJsonValidationErrors(['destination']);
    correctDelivery($this, $this->d->keeper, '1', 'stock', ['reason' => ''])->assertUnprocessable();
    correctDelivery($this, $this->d->keeper, '6', 'stock')->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    correctDelivery($this, $this->d->keeper, '0', 'stock')->assertUnprocessable();
    expect(DeliveryCorrection::query()->count())->toBe(0);

    $key = (string) Str::uuid();
    correctDelivery($this, $this->d->keeper, '1', 'stock', ['idempotency_key' => $key, 'lock_version' => $this->note->refresh()->lock_version])->assertCreated();
    correctDelivery($this, $this->d->keeper, '1', 'stock', ['idempotency_key' => $key, 'lock_version' => $this->note->lock_version])->assertCreated();
    expect(DeliveryCorrection::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('kind', 'correction_in')->count())->toBe(1);

    // The note moved on: an old screen is refused (409), and the total never exceeds what the note delivered.
    correctDelivery($this, $this->d->keeper, '1', 'stock', ['lock_version' => $this->note->lock_version])->assertStatus(409);
    correctDelivery($this, $this->d->keeper, '5', 'stock')->assertUnprocessable();
    correctDelivery($this, $this->d->keeper, '4', 'reservation')->assertCreated();
    expect(DeliveryProgress::deliveredByLine($this->order->id))->toBe([$this->line => 0]);
    stockJournalMatches();
});

it('corrects a made-to-order delivery without any stock movement: the quantity is owed again', function () {
    $f = $this->d->s->f;
    $payload = customPayload($f->inA);
    $payload['custom'] = ['need_summary' => 'x', 'lines' => [['type' => 'custom_product', 'product_id' => $f->product->id, 'quantity' => '5']]];
    $this->actingAs($this->d->owner)->postJson('/orders', $payload)->assertCreated();
    $order = lastOrder();
    $line = deliveryLineId($order);
    $note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $order, [$line => '3']));
    $movements = StockMovement::query()->count();

    $this->actingAs($this->d->keeper)->postJson(route('deliveries.corrections.store', $note), [
        'lines' => [['line_id' => $note->lines()->firstOrFail()->id, 'quantity' => '1']], 'reason' => 'قطعة لم تُركّب', 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();

    expect(StockMovement::query()->count())->toBe($movements)
        ->and(DeliveryCorrection::query()->latest('id')->firstOrFail()->destination)->toBeNull()
        ->and(DeliveryProgress::lines($order->refresh())[$line]['deliverable'])->toBe(3000);
});

it('shows the corrections and the net quantity on the delivery page', function () {
    correctDelivery($this, $this->d->keeper, '2', 'reservation')->assertCreated();

    $this->actingAs($this->d->keeper)->get(route('deliveries.show', $this->note))->assertOk()
        ->assertInertia(fn ($p) => $p->where('note.can.correct', true)->where('note.can.correct_needs_destination', true)
            ->where('note.lines.0.net', '3')->where('note.lines.0.corrected', '2')
            ->where('note.corrections.0.destination', 'reservation')->has('note.corrections', 1));
});

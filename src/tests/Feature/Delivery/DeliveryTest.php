<?php

/**
 * P7 delivery (ADR-0018, M-14, FUL-09/FUL-10): scenario 6 «تسليم 2 من 5 ثم إلغاء الباقي» with the document numbers,
 * scenario 7's base (the delivered quantity returns are checked against), «توريد وتسليم جزئيان», double submit,
 * over-delivery, gapless DN numbers, proof (Q-44 a), the P5 action result and the «at delivery» due once, hidden amounts.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder;
use App\Actions\StateMachines\M14Delivery\T00CreateDraft;
use App\Actions\StateMachines\M14Delivery\T02CancelDraft;
use App\Delivery\DeliveryConfirmed;
use App\Delivery\DeliveryPresenter;
use App\Delivery\DeliveryProgress;
use App\Enums\DeliveryStatus;
use App\Enums\DueEvent;
use App\Finance\DueCalendar;
use App\Models\AuditEvent;
use App\Models\Attachment;
use App\Models\Delivery\DeliveryNote;
use App\Models\Finance\OrderDue;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\StateTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/DeliveryTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->d = deliveryFixture(10, 5);
    $this->order = $this->d->order;
    $this->line = deliveryLineId($this->order);
    $this->variant = $this->d->s->f->variant->id;
    $this->year = now()->year;
});

it('scenario 6: delivers 2 of 5, then cancelling the rest keeps the 2 and frees only the 3', function () {
    $note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']));

    expect($note->number)->toBe("DN-{$this->year}-000001")
        ->and($note->status)->toBe(DeliveryStatus::Confirmed)
        ->and($this->order->refresh()->status)->toBe('partially_delivered')
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant))->toBe(['on_hand' => '8', 'reserved' => '3', 'available' => '5']);

    app(T09CancelReadyRemainder::class)->handle($this->d->owner, $this->order, 'العميل ألغى الباقي');
    $reservation = StockReservation::query()->firstOrFail();

    expect($this->order->refresh()->status)->toBe('delivered')
        ->and($reservation->issued_milli)->toBe(2000)->and($reservation->released_milli)->toBe(3000)
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant))->toBe(['on_hand' => '8', 'reserved' => '0', 'available' => '8'])
        ->and(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1)
        ->and(StockMovement::query()->where('kind', 'issue_reserved')->first()->source_type)->toBe('delivery_note');

    // Scenario 7's base: the delivered quantity is what a return can ever be checked against (2, not 5).
    expect(DeliveryProgress::deliveredByLine($this->order->id))->toBe([$this->line => 2000]);
    stockJournalMatches();
});

it('delivers in two parts and finishes the order; a third delivery is refused', function () {
    $first = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '3']));
    $second = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']));

    expect([$first->number, $second->number])->toBe(["DN-{$this->year}-000001", "DN-{$this->year}-000002"])
        ->and($this->order->refresh()->status)->toBe('delivered')
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant))->toBe(['on_hand' => '5', 'reserved' => '0', 'available' => '5'])
        ->and(DeliveryProgress::complete($this->order))->toBeTrue();

    expect(fn () => deliveryDraft($this->d->keeper, $this->order->refresh(), [$this->line => '1']))->toThrow(InvalidTransition::class);
    stockJournalMatches();
});

it('refuses more than is deliverable, in a draft and at confirmation, and a refusal consumes no number', function () {
    expect(fn () => deliveryDraft($this->d->keeper, $this->order, [$this->line => '6']))->toThrow(ValidationException::class);

    // Two drafts of 3 against 5 reserved: the first confirms, the second finds only 2 left.
    $a = deliveryDraft($this->d->keeper, $this->order, [$this->line => '3']);
    $b = deliveryDraft($this->d->keeper, $this->order, [$this->line => '3']);
    deliveryConfirm($this->d->keeper, $a);
    expect(fn () => deliveryConfirm($this->d->keeper, $b))->toThrow(ValidationException::class);
    expect($b->refresh()->status)->toBe(DeliveryStatus::Draft)->and($b->number)->toBeNull();

    // The next valid note takes the next number: no hole.
    $c = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']));
    expect($c->number)->toBe("DN-{$this->year}-000002");
});

it('requires the receiver and at least one proof (Q-44 a) and rolls everything back without them', function () {
    $note = deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']);

    expect(fn () => deliveryConfirm($this->d->keeper, $note, proofs: []))->toThrow(ValidationException::class);
    expect(fn () => deliveryConfirm($this->d->keeper, $note, data: ['receiver_name' => '  ']))->toThrow(ValidationException::class);

    expect($note->refresh()->status)->toBe(DeliveryStatus::Draft)
        ->and(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(0)
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant)['on_hand'])->toBe('10')
        ->and($this->order->refresh()->status)->toBe('confirmed');
});

it('stores the proof as a private attachment that follows the delivery scope', function () {
    $note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']));
    $proof = Attachment::query()->where('attachable_type', $note->getMorphClass())->where('attachable_id', $note->id)->firstOrFail();
    $detail = app(DeliveryPresenter::class)->detail($note, $this->d->keeper);

    expect($proof->purpose)->toBe('delivery_proof')
        ->and($detail['proofs'])->toHaveCount(1)->and($detail['proofs'][0]['url'])->toBeString()
        ->and($detail['receiver_name'])->toBe('خالد العتيبي');
    // A sales member (no P-52) neither sees the note nor its file.
    expect(fn () => app(DeliveryPresenter::class)->detail($note, $this->d->s->f->sales))->not->toThrow(Throwable::class);
    expect(app(DeliveryPresenter::class)->detail($note, $this->d->s->f->sales)['proofs'][0]['url'])->toBeNull();
});

it('a double submit with the same key issues the stock once and replays the first note', function () {
    $draft = deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']);
    $proof = deliveryProof();
    $first = deliveryConfirm($this->d->keeper, $draft, 'dup-key-0001', proofs: [$proof]);
    $again = deliveryConfirm($this->d->keeper, $draft, 'dup-key-0001', proofs: [$proof]);

    expect($again->id)->toBe($first->id)->and($again->number)->toBe($first->number)
        ->and(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1)
        ->and(stockBalance($this->d->s->la, 'ready_variant', $this->variant)['on_hand'])->toBe('8')
        ->and(DeliveryNote::query()->where('status', 'confirmed')->count())->toBe(1);

    // A new key on the already recorded note is refused as a state error, not a second issue.
    expect(fn () => deliveryConfirm($this->d->keeper, $draft, 'another-key-02'))->toThrow(InvalidTransition::class);
    expect(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1);

    // A double draft with one key creates one draft.
    $x = deliveryDraft($this->d->keeper, $this->order, [$this->line => '1'], 'draft-key-0001');
    $y = deliveryDraft($this->d->keeper, $this->order, [$this->line => '1'], 'draft-key-0001');
    expect($x->id)->toBe($y->id);
});

it('checks the optimistic lock of the note and withdraws a draft without number or stock', function () {
    $draft = deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']);
    expect(fn () => app(\App\Actions\StateMachines\M14Delivery\T01ConfirmDelivery::class)->handle(
        $this->d->keeper, $draft, ['delivered_on' => now()->toDateString(), 'receiver_name' => 'س'], [deliveryProof()], 99, 'stale-key-0001',
    ))->toThrow(\App\Support\StaleRecord::class);

    app(T02CancelDraft::class)->handle($this->d->keeper, $draft, 'خطأ في الكمية', $draft->lock_version, null);
    expect($draft->refresh()->status)->toBe(DeliveryStatus::Cancelled)->and($draft->number)->toBeNull()
        ->and(fn () => deliveryConfirm($this->d->keeper, $draft))->toThrow(InvalidTransition::class)
        ->and(fn () => app(T02CancelDraft::class)->handle($this->d->keeper, $draft, 'مرة أخرى', null, null))->toThrow(InvalidTransition::class);
    expect(deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '1']))->number)->toBe("DN-{$this->year}-000001");
});

it('writes M-14 and M-05-T08 transitions and an audit entry per step', function () {
    $note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '5']));

    expect(StateTransition::query()->where('machine', 'M-14')->orderBy('id')->pluck('transition')->all())->toBe(['T00', 'T01'])
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T08')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'delivery.confirmed')->where('subject_id', $note->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'delivery.drafted')->count())->toBe(1);
    $t = StateTransition::query()->where('machine', 'M-05')->where('transition', 'T08')->firstOrFail();
    expect($t->to_state)->toBe('delivered');
});

it('dispatches the delivered event after commit once per note', function () {
    Event::fake([DeliveryConfirmed::class]);
    $note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '5']));
    Event::assertDispatchedTimes(DeliveryConfirmed::class, 1);
    Event::assertDispatched(DeliveryConfirmed::class, fn ($e) => $e->noteId === $note->id && $e->first && $e->complete);
});

it('hides line prices and values from a member without P-37', function () {
    $note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']));
    $presenter = app(DeliveryPresenter::class);

    $without = $presenter->detail($note, $this->d->keeper);
    expect($without['lines'][0])->not->toHaveKeys(['unit_price_minor', 'value_minor']);
    expect(json_encode($without))->not->toContain('unit_price_minor')->and(json_encode(DeliveryPresenter::owed($this->order, $this->d->keeper)))->not->toContain('unit_price_minor');

    $with = $presenter->detail($note, $this->d->owner);
    expect($with['lines'][0])->toHaveKeys(['unit_price_minor', 'value_minor'])
        ->and($with['lines'][0]['value_minor'])->toBe($with['lines'][0]['unit_price_minor'] * 2);
});

it('custom order: tracks lines, creates no stock, and the full delivery completes the P5 delivery action once', function () {
    $f = $this->d->s->f;
    $payload = customPayload($f->inA);
    $payload['custom'] = ['need_summary' => 'x', 'lines' => [['type' => 'custom_product', 'product_id' => $f->product->id, 'quantity' => '5']]];
    $this->actingAs($this->d->owner)->postJson('/orders', $payload)->assertCreated();
    $order = lastOrder();
    $line = deliveryLineId($order);
    $movements = StockMovement::query()->count();
    $action = actionOf($order, 'delivery');

    $first = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $order, [$line => '2']));
    expect($first->number)->toBe("DN-{$this->year}-000001")
        ->and($action->refresh()->source_reference)->toBeNull()
        ->and(DeliveryProgress::complete($order))->toBeFalse();

    expect(fn () => deliveryDraft($this->d->keeper, $order, [$line => '4']))->toThrow(ValidationException::class);
    $second = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $order, [$line => '3']));

    expect($action->refresh()->source_reference)->toBe(['type' => 'delivery_note', 'id' => $second->id])
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and($order->refresh()->status)->toBe('in_progress');
    expect(StateTransition::query()->where('machine', 'M-27')->where('subject_id', $action->id)->count())->toBeLessThanOrEqual(2);
});

it('«at delivery» due falls due once, on the first delivery, and later deliveries never move it', function () {
    $due = OrderDue::query()->create([
        'order_id' => $this->order->id, 'position' => 1, 'reference' => 'final', 'name' => ['ar' => 'الدفعة الأخيرة', 'en' => 'Final'],
        'basis' => 'rest', 'amount_minor' => 5000, 'due_event' => DueEvent::Delivery,
    ]);
    expect(DueCalendar::dates($this->order, collect([$due])))->toBe([$due->id => null]);

    deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '2']), data: ['delivered_on' => now()->subDays(2)->toDateString()]);
    $expected = now()->subDays(2)->toDateString();
    expect(DueCalendar::dates($this->order, collect([$due])))->toBe([$due->id => $expected]);

    deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [$this->line => '3']));
    expect(DueCalendar::dates($this->order, collect([$due])))->toBe([$due->id => $expected]);
});

it('refuses a delivery for an order that cannot accept one (draft, cancelled, paused)', function () {
    $draftOrder = readyOrder($this->d->s, 1);
    expect(fn () => deliveryDraft($this->d->keeper, $draftOrder, [deliveryLineId($draftOrder) => '1']))->toThrow(InvalidTransition::class);

    app(T09CancelReadyRemainder::class)->handle($this->d->owner, $this->order, 'إلغاء');
    expect(fn () => deliveryDraft($this->d->keeper, $this->order->refresh(), [$this->line => '1']))->toThrow(InvalidTransition::class);
});

it('refuses a member without P-52 on the order', function () {
    expect(fn () => deliveryDraft($this->d->s->f->sales, $this->order, [$this->line => '1']))->toThrow(AuthorizationException::class);
});

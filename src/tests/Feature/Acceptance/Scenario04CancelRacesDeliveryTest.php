<?php

/**
 * Mandatory integration scenario 4 «إلغاء يتزامن مع تسليم» (SRS «اختبارات ترابط ملزمة»; SRS-INT-01, P03/P04), driven
 * only over HTTP. Both screens are opened on the same state (the owner's cancellation review with its fingerprint and
 * the order's lock_version; the inventory officer's delivery draft with the note's lock_version), then the two
 * decisions arrive one after the other. The first proven effect wins; the later one is refused and must be reviewed
 * again on the new state — its quantity backs out. A recorded delivery is never cancelled and stock is never
 * increased twice. The real-process race is pinned in Cancellation/CancellationRaceTest.
 */

use App\Models\Inventory\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceStockHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = accSReadyFixture(10, 5);
    $this->year = now()->year;
});

it('delivery first: the stale cancellation is refused, the re-reviewed one releases only what was not delivered', function () {
    $f = $this->f;
    // Both screens open on the same state.
    $stale = accSPreview($f->owner, $f->order);
    $draft = accSDraft($f->keeper, $f->order, $f->line, '2');
    expect(array_column($stale['impact']['settlement']['stock'], 'release_milli'))->toBe([5000]);

    // The delivery is recorded first (proven effect: receiver + proof, DN number).
    $deliveryKey = accSKey();
    accSConfirmNote($f->keeper, $draft, $deliveryKey)->assertOk()->assertJsonPath('note.number', "DN-{$this->year}-000001");

    // The cancellation built on the old review arrives second: refused, nothing applied.
    $before = accSJournal();
    $refused = accSCancel($f->owner, $f->order, ['scope' => 'full'], $stale['hash'], $stale['lock_version'])->assertStatus(409);
    expect($refused->json('code'))->toBe('stale_version')
        ->and(DB::table('order_cancellations')->count())->toBe(0)
        ->and(accSJournal())->toBe($before)
        ->and(accSReservation($f->order))->toBe(['quantity' => 5000, 'issued' => 2000, 'released' => 0, 'status' => 'partially_issued']);
    // Even with the fresh version, the old fingerprint no longer matches what stays with the customer.
    accSCancel($f->owner, $f->order, ['scope' => 'full'], $stale['hash'], $f->order->refresh()->lock_version)
        ->assertStatus(409)->assertJsonPath('condition', 'preview_stale');

    // Reviewed again: 2 delivered stay, only 3 are released.
    $fresh = accSPreview($f->owner, $f->order);
    expect($fresh['impact']['stays']['delivered'][0]['delivered_milli'])->toBe(2000)
        ->and(array_column($fresh['impact']['settlement']['stock'], 'release_milli'))->toBe([3000]);
    $cancelKey = accSKey();
    accSCancel($f->owner, $f->order, ['scope' => 'full'], $fresh['hash'], $fresh['lock_version'], $cancelKey)->assertCreated()
        ->assertJsonPath('cancellation.number', "CN-{$this->year}-000001")->assertJsonPath('order.status', 'delivered');

    // Recording the same note again is refused (409); a replay of the cancellation changes nothing.
    accSConfirmNote($f->keeper, $draft)->assertStatus(409);
    accSCancel($f->owner, $f->order, ['scope' => 'full'], $fresh['hash'], $fresh['lock_version'], $cancelKey)->assertSuccessful();

    expect(accSReservation($f->order))->toBe(['quantity' => 5000, 'issued' => 2000, 'released' => 3000, 'status' => 'settled_mixed'])
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 8000, 'reserved' => 0, 'available' => 8000])
        ->and(accSJournal())->toBe([
            ['receipt', 10000, 10000, 0],
            ['reserve', 5000, 0, 5000],
            ['issue_reserved', 2000, -2000, -2000],
            ['release', 3000, 0, -3000],
        ])
        // The recorded delivery was not cancelled.
        ->and(DB::table('delivery_notes')->where('status', 'confirmed')->pluck('number')->all())->toBe(["DN-{$this->year}-000001"])
        ->and(DB::table('order_cancellations')->count())->toBe(1)
        ->and(accSAudits('cancellation.accepted'))->toBe(1)
        ->and(accSAudits('delivery.confirmed'))->toBe(1);
    stockJournalMatches();
});

it('cancellation first: the delivery drafted before it is refused and stock is released once, never issued', function () {
    $f = $this->f;
    $draft = accSDraft($f->keeper, $f->order, $f->line, '2');
    $review = accSPreview($f->owner, $f->order);

    accSCancel($f->owner, $f->order, ['scope' => 'full'], $review['hash'], $review['lock_version'])->assertCreated()
        ->assertJsonPath('order.status', 'cancelled');

    // The delivery from the screen opened before arrives second: refused by state, no number, no stock leaves.
    accSConfirmNote($f->keeper, $draft)->assertStatus(409)->assertJsonPath('condition', 'order_cancelled');

    expect(accSReservation($f->order))->toBe(['quantity' => 5000, 'issued' => 0, 'released' => 5000, 'status' => 'released'])
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 10000, 'reserved' => 0, 'available' => 10000])
        ->and(accSJournal())->toBe([
            ['receipt', 10000, 10000, 0],
            ['reserve', 5000, 0, 5000],
            ['release', 5000, 0, -5000],
        ])
        ->and(DB::table('delivery_notes')->whereNotNull('number')->count())->toBe(0)
        ->and(DB::table('delivery_notes')->where('status', 'confirmed')->count())->toBe(0)
        ->and(accSAudits('delivery.confirmed'))->toBe(0)
        ->and(cnItems($f->order))->toBe(['stock_release' => 'done']);

    // A new draft on the cancelled order is refused as well.
    $this->actingAs($f->keeper)->postJson("/orders/{$f->order->id}/deliveries", [
        'idempotency_key' => accSKey(), 'lines' => [['order_line_id' => $f->line, 'quantity' => '1']],
    ])->assertStatus(409);
    expect(StockMovement::query()->count())->toBe(3);
    stockJournalMatches();
});

it('a full delivery recorded first is never cancelled: the late cancellation is refused without releasing anything', function () {
    $f = $this->f;
    $review = accSPreview($f->owner, $f->order);
    accSDeliver($f->keeper, $f->order, $f->line, '5');
    $journal = accSJournal();

    accSCancel($f->owner, $f->order, ['scope' => 'full'], $review['hash'], $f->order->refresh()->lock_version)->assertStatus(409);
    // Reviewing again does not open a way to cancel a delivered order either.
    $this->actingAs($f->owner)->postJson("/orders/{$f->order->id}/cancellation/preview", ['scope' => 'full'])->assertStatus(409);

    expect($f->order->refresh()->status)->toBe('delivered')
        ->and(accSReservation($f->order))->toBe(['quantity' => 5000, 'issued' => 5000, 'released' => 0, 'status' => 'issued'])
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 5000, 'reserved' => 0, 'available' => 5000])
        ->and(accSJournal())->toBe($journal)
        ->and(DB::table('order_cancellations')->count())->toBe(0)
        ->and(DB::table('delivery_notes')->where('status', 'confirmed')->count())->toBe(1);
    stockJournalMatches();
});

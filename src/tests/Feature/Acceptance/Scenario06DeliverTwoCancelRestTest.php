<?php

/**
 * Mandatory integration scenario 6 «تسليم2 من5 ثم إلغاء الباقي» (SRS «اختبارات ترابط ملزمة»; SRS-FUL-09, SRS-INT-01, P03),
 * driven only over HTTP: the inventory officer records a delivery of 2 of a reserved ready order of 5, then the owner
 * cancels the rest. The delivery of 2 stays, only the 3 reserved and not issued are released, and no return of 5 (or
 * of anything) is recorded — stock never comes back for what the customer kept.
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

it('keeps the delivery of 2, releases only the 3 not issued and records no return (cancellation review)', function () {
    $f = $this->f;
    expect(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 10000, 'reserved' => 5000, 'available' => 5000]);

    $note = accSDeliver($f->keeper, $f->order, $f->line, '2');
    expect($note['number'])->toBe("DN-{$this->year}-000001")
        ->and($f->order->refresh()->status)->toBe('partially_delivered')
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 8000, 'reserved' => 3000, 'available' => 5000]);

    // The review shows what stays (2 delivered) and what is released (3), never the whole 5.
    $review = accSPreview($f->owner, $f->order);
    expect($review['impact']['stays']['delivered'][0]['delivered_milli'])->toBe(2000)
        ->and(array_column($review['impact']['settlement']['stock'], 'release_milli'))->toBe([3000])
        ->and($review['impact']['order']['status_after'])->toBe('delivered')
        // Money: 5 × 45.00 before, the 2 delivered stay owed after (no fee invented, nothing collected).
        ->and($review['impact']['settlement']['money'])->toMatchArray(['collected_minor' => 0, 'value_before_minor' => 22500, 'value_after_minor' => 9000, 'refund_minor' => 0]);

    $key = accSKey();
    accSCancel($f->owner, $f->order, ['scope' => 'full'], $review['hash'], $review['lock_version'], $key)->assertCreated()
        ->assertJsonPath('cancellation.number', "CN-{$this->year}-000001")
        ->assertJsonPath('cancellation.status', 'accepted')
        ->assertJsonPath('order.status', 'delivered');
    // A double submit of the same decision applies once.
    accSCancel($f->owner, $f->order, ['scope' => 'full'], $review['hash'], $review['lock_version'], $key)->assertSuccessful();

    expect(accSReservation($f->order))->toBe(['quantity' => 5000, 'issued' => 2000, 'released' => 3000, 'status' => 'settled_mixed'])
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 8000, 'reserved' => 0, 'available' => 8000])
        ->and(accSJournal())->toBe([
            ['receipt', 10000, 10000, 0],
            ['reserve', 5000, 0, 5000],
            ['issue_reserved', 2000, -2000, -2000],
            ['release', 3000, 0, -3000],
        ])
        // The recorded delivery is untouched.
        ->and(DB::table('delivery_notes')->where('status', 'confirmed')->pluck('number')->all())->toBe(["DN-{$this->year}-000001"])
        ->and((int) DB::table('delivery_note_lines')->sum('quantity_milli'))->toBe(2000)
        // No return of 5 — no return at all, and nothing came back into stock.
        ->and(DB::table('order_returns')->count())->toBe(0)
        ->and(StockMovement::query()->whereIn('kind', ['return_in', 'return_damaged'])->count())->toBe(0)
        ->and(DB::table('order_cancellations')->count())->toBe(1)
        ->and(DB::table('state_transitions')->where('machine', 'M-05')->where('transition', 'T09')->count())->toBe(1)
        ->and($f->order->refresh()->total_minor)->toBe(9000)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(accSAudits('cancellation.accepted'))->toBe(1)
        ->and(accSAudits('delivery.confirmed'))->toBe(1)
        // Ready order without money: the follow-up holds only the release, already done.
        ->and(cnItems($f->order))->toBe(['stock_release' => 'done'])
        ->and(cnFollowUp($f->order)?->status)->toBe('closed');
    stockJournalMatches();

    // Nothing is deliverable any more: a further draft is refused and stock does not move.
    $this->actingAs($f->keeper)->postJson("/orders/{$f->order->id}/deliveries", [
        'idempotency_key' => accSKey(), 'lines' => [['order_line_id' => $f->line, 'quantity' => '1']],
    ])->assertStatus(409);
    expect(StockMovement::query()->count())->toBe(4);
});

it('keeps the delivery of 2 and releases only 3 through the ready order\'s cancel-remainder (M-05-T09)', function () {
    $f = $this->f;
    accSDeliver($f->keeper, $f->order, $f->line, '2');

    $this->actingAs($f->owner)->postJson("/orders/{$f->order->id}/cancel-remainder", [
        'reason' => 'العميل اكتفى بقطعتين', 'lock_version' => $f->order->refresh()->lock_version,
    ])->assertOk()->assertJsonPath('order.status', 'delivered');

    expect(accSReservation($f->order))->toBe(['quantity' => 5000, 'issued' => 2000, 'released' => 3000, 'status' => 'settled_mixed'])
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 8000, 'reserved' => 0, 'available' => 8000])
        ->and(StockMovement::query()->where('kind', 'release')->pluck('quantity_milli')->all())->toBe([3000])
        ->and(DB::table('delivery_notes')->where('status', 'confirmed')->count())->toBe(1)
        ->and(DB::table('order_returns')->count())->toBe(0);
    stockJournalMatches();
});

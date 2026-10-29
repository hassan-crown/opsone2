<?php

/**
 * Mandatory integration scenario 7 «مرتجعان معتمدان من مسلم2» (SRS «اختبارات ترابط ملزمة»; SRS-INT-03, P04), driven only
 * over HTTP: the inventory officer delivers 2 of a ready order, two return requests for 2 each are raised, and the
 * manager (P-102) decides them. The acceptance holds the eligible quantity, so no more than the 2 delivered can ever
 * be accepted; eligible = delivered − received − accepted-not-received, and a receipt is never subtracted twice.
 * Withdrawing the unreceived remainder frees exactly that remainder.
 */

use App\Models\Inventory\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceStockHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = accSReadyFixture(10, 2);
    $this->year = now()->year;
    accSDeliver($this->f->keeper, $this->f->order, $this->f->line, '2');
});

it('never accepts more than the 2 delivered across two approved returns and never subtracts a receipt twice', function () {
    $f = $this->f;
    expect(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 8000, 'reserved' => 0, 'available' => 8000]);

    // Requests hold nothing: both may ask for the whole delivered quantity.
    $a = accSReturnRequest($f->keeper, $f->order, $f->line, '2');
    $b = accSReturnRequest($f->manager, $f->order, $f->line, '2');
    expect([$a['number'], $b['number']])->toBe(["RT-{$this->year}-000001", "RT-{$this->year}-000002"])
        ->and(accSEligibility($f->keeper, $f->order))->toBe(['delivered' => '2', 'held' => '0', 'eligible' => '2']);

    // The first acceptance holds the delivered 2; the second is refused without effect.
    accSReturnAccept($f->manager, $a['id'])->assertOk()->assertJsonPath('return.status', 'accepted');
    expect(accSEligibility($f->keeper, $f->order))->toBe(['delivered' => '2', 'held' => '2', 'eligible' => '0']);
    $audits = DB::table('audit_events')->count();
    accSReturnAccept($f->manager, $b['id'])->assertStatus(409)->assertJsonPath('condition', 'over_delivered');
    expect(DB::table('order_returns')->where('id', $b['id'])->value('status'))->toBe('requested')
        ->and(DB::table('audit_events')->count())->toBe($audits);

    // Receiving 1 of A: received is part of what A holds, never subtracted a second time.
    accSReturnReceive($f->keeper, $a['id'], $f->line, $f->location, '1')->assertOk()->assertJsonPath('return.status', 'received');
    expect(accSEligibility($f->keeper, $f->order))->toBe(['delivered' => '2', 'held' => '2', 'eligible' => '0'])
        // Received but not inspected: nothing is available yet.
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 8000, 'reserved' => 0, 'available' => 8000]);

    // Withdrawing A's unreceived remainder frees exactly 1.
    $this->actingAs($f->manager)->postJson("/returns/{$a['id']}/withdraw", [
        'idempotency_key' => accSKey(), 'reason' => 'العميل احتفظ بقطعة', 'lock_version' => accSReturnVersion($f->manager, $a['id']),
    ])->assertOk()->assertJsonPath('return.status', 'received');
    expect(DB::table('order_return_lines')->where('order_return_id', $a['id'])->value('accepted_milli'))->toBe(1000)
        ->and(DB::table('order_return_lines')->where('order_return_id', $a['id'])->value('received_milli'))->toBe(1000)
        ->and(accSEligibility($f->keeper, $f->order))->toBe(['delivered' => '2', 'held' => '1', 'eligible' => '1']);

    // B may now accept 1, not 2.
    accSReturnAccept($f->manager, $b['id'], ['lines' => [['order_line_id' => $f->line, 'quantity' => '2']]])
        ->assertStatus(409)->assertJsonPath('condition', 'over_delivered');
    accSReturnAccept($f->manager, $b['id'], ['lines' => [['order_line_id' => $f->line, 'quantity' => '1']]])
        ->assertOk()->assertJsonPath('return.status', 'accepted');
    expect(accSEligibility($f->keeper, $f->order))->toBe(['delivered' => '2', 'held' => '2', 'eligible' => '0']);

    // A is inspected good; B is received and inspected good in one step: each unit comes back once.
    $part = $this->actingAs($f->keeper)->getJson("/returns/{$a['id']}")->assertOk()->json('return.parts.0.id');
    $this->actingAs($f->keeper)->postJson("/returns/{$a['id']}/inspect", [
        'idempotency_key' => accSKey(), 'lock_version' => accSReturnVersion($f->keeper, $a['id']),
        'results' => [['part_id' => $part, 'good' => '1', 'damaged' => '0', 'rejected' => '0']],
    ])->assertOk()->assertJsonPath('return.status', 'inspected');
    accSReturnReceive($f->keeper, $b['id'], $f->line, $f->location, '1', ['1', '0', '0'])->assertOk()->assertJsonPath('return.status', 'inspected');

    expect(accSEligibility($f->keeper, $f->order))->toBe(['delivered' => '2', 'held' => '2', 'eligible' => '0'])
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 10000, 'reserved' => 0, 'available' => 10000])
        ->and(StockMovement::query()->where('source_type', 'order_return_part')->orderBy('id')->get()
            ->map(fn ($m) => [$m->kind->value, (int) $m->quantity_milli, $m->document_number])->all())->toBe([
                ['return_in', 1000, "RT-{$this->year}-000001"],
                ['return_in', 1000, "RT-{$this->year}-000002"],
            ])
        ->and(DB::table('order_return_lines')->sum('accepted_milli'))->toBe(2000)
        ->and(DB::table('order_return_lines')->sum('received_milli'))->toBe(2000)
        // A return neither creates a replacement delivery nor touches money by itself.
        ->and(DB::table('delivery_notes')->count())->toBe(1)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(accSAudits('return.accepted'))->toBe(2)
        ->and(accSAudits('return.withdrawn'))->toBe(1);
    stockJournalMatches();

    // No third request can be raised on what is fully returned.
    $this->actingAs($f->keeper)->postJson("/orders/{$f->order->id}/returns", [
        'idempotency_key' => accSKey(), 'reason' => 'مرتجع إضافي', 'lines' => [['order_line_id' => $f->line, 'quantity' => '1']],
    ])->assertStatus(422);
});

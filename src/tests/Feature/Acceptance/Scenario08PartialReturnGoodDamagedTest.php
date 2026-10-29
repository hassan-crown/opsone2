<?php

/**
 * Mandatory integration scenario 8 «مرتجع جزئي صالح وتالف» (SRS «اختبارات ترابط ملزمة»; SRS-INT-02, SRS-INT-05, P04/P05),
 * driven only over HTTP: a ready order of 5 × 45.00 is delivered and paid in full; 3 come back, inspected as 2 good and
 * 1 damaged. Only the good 2 increase available (damaged stays in custody); the damage alone does not decide the
 * customer's money — the settlement fixed at acceptance does: the refund is limited by it, requested by P-42 and paid
 * by P-43 with proof, and the money journal is reduced once.
 */

use App\Finance\FinanceLock;
use App\Finance\MoneyLedger;
use App\Models\Inventory\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/AcceptanceStockHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $f = accSReadyFixture(10, 5);
    accSDeliver($f->keeper, $f->order, $f->line, '5');
    expect($f->order->refresh()->total_minor)->toBe(22500);

    // The customer paid 225.00 at the counter (finance officer, P-39).
    $this->actingAs($f->finance)->post("/orders/{$f->order->id}/finance/payments", [
        'amount' => '225.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-ACC-8',
        'idempotency_key' => accSKey(), 'lock_version' => FinanceLock::versionOf($f->order),
        'evidence' => UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\npaid"),
    ], ['Accept' => 'application/json'])->assertCreated();
    $f->payment = (int) DB::table('payments')->where('order_id', $f->order->id)->value('id');
    $this->f = $f;
    $this->year = now()->year;
});

/**
 * Requests a 3-unit return and has the manager accept it with the given settlement (null = the value of the lines).
 *
 * @return array<string, mixed>
 */
function accS8Accepted(object $f, ?string $settlement): array
{
    $return = accSReturnRequest($f->keeper, $f->order, $f->line, '3');
    accSReturnAccept($f->manager, $return['id'], $settlement === null ? [] : ['settlement' => $settlement])->assertOk()->assertJsonPath('return.status', 'accepted');

    return $return;
}

function accS8Refund(object $f, int $returnId, string $amount): TestResponse
{
    return test()->actingAs($f->manager)->postJson("/returns/{$returnId}/refund", [
        'idempotency_key' => accSKey(), 'lock_version' => accSReturnVersion($f->manager, $returnId),
        'payment_id' => $f->payment, 'amount' => $amount, 'method' => 'transfer', 'reason' => 'رد قيمة المرتجع',
    ]);
}

it('adds only the good 2 to available, keeps the damaged 1 in custody, and refunds the settlement once', function () {
    $f = $this->f;
    expect(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 5000, 'reserved' => 0, 'available' => 5000]);

    $return = accS8Accepted($f, null);
    // The commercial acceptance fixes the value: 3 × 45.00.
    expect(DB::table('order_returns')->where('id', $return['id'])->value('value_minor'))->toBe(13500)
        ->and(DB::table('order_returns')->where('id', $return['id'])->value('settlement_minor'))->toBe(13500)
        // Accepted, not received: stock does not move.
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 5000, 'reserved' => 0, 'available' => 5000]);

    accSReturnReceive($f->keeper, $return['id'], $f->line, $f->location, '3', ['2', '1', '0'])->assertOk()
        ->assertJsonPath('return.status', 'inspected')->assertJsonPath('return.lines.0.good', '2')->assertJsonPath('return.lines.0.damaged', '1');

    expect(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 7000, 'reserved' => 0, 'available' => 7000])
        ->and(StockMovement::query()->where('source_type', 'order_return_part')->orderBy('id')->get()
            ->map(fn ($m) => [$m->kind->value, (int) $m->quantity_milli, (int) $m->on_hand_delta, (int) $m->reserved_delta, $m->document_number])->all())->toBe([
                ['return_in', 2000, 2000, 0, "RT-{$this->year}-000001"],
                ['return_damaged', 1000, 0, 0, "RT-{$this->year}-000001"],
            ])
        // Inspection does not decide the money: the settlement still covers all 3 and nothing was refunded.
        ->and(DB::table('order_returns')->where('id', $return['id'])->value('settlement_minor'))->toBe(13500)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(MoneyLedger::collected($f->order->id)->minor)->toBe(22500)
        ->and(cnItems($f->order))->toBe(["return:{$return['id']}" => 'open']);

    // The refund is limited by the settlement, not by what was found damaged.
    accS8Refund($f, $return['id'], '135.01')->assertStatus(409)->assertJsonPath('condition', 'above_settlement');
    $refundId = (int) accS8Refund($f, $return['id'], '135.00')->assertCreated()->json('refund_id');
    expect(DB::table('refunds')->where('id', $refundId)->value('amount_minor'))->toBe(13500)
        ->and(DB::table('refunds')->where('id', $refundId)->value('status'))->toBe('requested')
        // Requested, not paid: the journal is not reduced yet.
        ->and(MoneyLedger::collected($f->order->id)->minor)->toBe(22500);
    accS8Refund($f, $return['id'], '1.00')->assertStatus(409)->assertJsonPath('condition', 'refund_exists');

    // P-43 pays it with proof: voucher RF-…, collected reduced once, follow-up item done.
    $this->actingAs($f->finance)->post("/finance/refunds/{$refundId}/confirm", [
        'reference' => 'TRX-ACC-8', 'proof' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\nrefund"),
        'idempotency_key' => accSKey(), 'lock_version' => (int) DB::table('refunds')->where('id', $refundId)->value('lock_version'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('number', "RF-{$this->year}-000001");

    expect(DB::table('refunds')->where('id', $refundId)->value('status'))->toBe('executed')
        ->and(DB::table('refunds')->count())->toBe(1)
        ->and(MoneyLedger::collected($f->order->id)->minor)->toBe(9000)
        ->and(cnItems($f->order))->toBe(["return:{$return['id']}" => 'done'])
        ->and(cnFollowUp($f->order)?->status)->toBe('closed')
        // Money did not move stock, stock did not move money.
        ->and(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 7000, 'reserved' => 0, 'available' => 7000])
        ->and(accSAudits('return.inspected'))->toBe(1)
        ->and(accSAudits('return.refund_requested'))->toBe(1)
        ->and(accSAudits('refund.executed'))->toBe(1);
    stockJournalMatches();
});

it('lets the settlement — not the damage — set the money: a lower agreed settlement caps the refund, zero asks none', function () {
    $f = $this->f;
    // Settlement agreed at 100.00 for the 3 (e.g. a deduction agreed with the customer).
    $return = accS8Accepted($f, '100.00');
    accSReturnReceive($f->keeper, $return['id'], $f->line, $f->location, '3', ['2', '1', '0'])->assertOk();

    expect(DB::table('order_returns')->where('id', $return['id'])->value('value_minor'))->toBe(13500)
        ->and(DB::table('order_returns')->where('id', $return['id'])->value('settlement_minor'))->toBe(10000)
        ->and(accSBalance($f->location, 'ready_variant', $f->variant)['available'])->toBe(7000);
    accS8Refund($f, $return['id'], '100.01')->assertStatus(409)->assertJsonPath('condition', 'above_settlement');
    accS8Refund($f, $return['id'], '100.00')->assertCreated();
    expect((int) DB::table('refunds')->sum('amount_minor'))->toBe(10000);
});

it('keeps the damaged unit out of available even when the settlement is zero, and asks no refund', function () {
    $f = $this->f;
    $return = accS8Accepted($f, '0');
    accSReturnReceive($f->keeper, $return['id'], $f->line, $f->location, '3', ['2', '1', '0'])->assertOk();

    expect(accSBalance($f->location, 'ready_variant', $f->variant))->toBe(['on_hand' => 7000, 'reserved' => 0, 'available' => 7000]);
    accS8Refund($f, $return['id'], '1.00')->assertStatus(409)->assertJsonPath('condition', 'no_settlement');
    expect(DB::table('refunds')->count())->toBe(0)->and(MoneyLedger::collected($f->order->id)->minor)->toBe(22500);
});

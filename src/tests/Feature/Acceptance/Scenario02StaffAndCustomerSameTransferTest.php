<?php

/**
 * Binding scenario 2 «تحويل واحد سجلته الإدارة ورفع العميل إيصالَه» («اختبارات ترابط ملزمة»): the proof is linked to the
 * same collection — exactly one receipt and one allocation.
 *
 * Traces: SRS-INT-04 (every money received has one original collection, whichever channel brings the evidence; a
 * matching trusted reference prevents a duplicate across channels), P05; M-10-T01, M-11-T01.
 *
 * Actors over HTTP: the finance officer records the transfer (`POST /orders/{order}/finance/payments`), the customer
 * signs in by code and uploads the receipt of the same transfer from the workspace (`POST /portal/orders/{order}/proofs`),
 * the finance officer confirms the proof (`POST /finance/proofs/{proof}/confirm`). The agreement is fixture setup.
 */

use App\Enums\PaymentStatus;
use App\Enums\ProofStatus;
use App\Finance\MoneyLedger;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptancePortalHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    Queue::fake(); // the sign-in code stays readable in the outbox
    $this->f = financeFixture();
    $this->order = $this->f->order;
    $this->customer = $this->order->customer;
    $this->customer->forceFill(['portal_login_enabled' => true])->save();
    finAgree($this->f, '18450'); // 18,450.00 → first due 9,225.00, final 9,225.00
});

function accP02RecordTransfer(object $f, Order $order, string $reference): array
{
    accPStaff($f->finance);

    return test()->post("/orders/{$order->id}/finance/payments", [
        'idempotency_key' => accPKey('pay'), 'lock_version' => finVersion($order),
        'amount' => '5000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => $reference,
        'evidence' => accPPdf('bank-transfer.pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json();
}

function accP02UploadProof(object $test, string $reference): PaymentProof
{
    accPFreshBrowser();
    portalSignIn($test->customer);
    $test->post("/portal/orders/{$test->order->id}/proofs", [
        'amount' => '5000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => $reference,
        'file' => accPPdf('my-receipt.pdf'),
    ])->assertSessionHasNoErrors()->assertRedirect("/portal/orders/{$test->order->id}");

    return PaymentProof::query()->where('order_id', $test->order->id)->latest('id')->firstOrFail();
}

function accP02Confirm(object $f, PaymentProof $proof): array
{
    accPStaff($f->finance);

    return test()->postJson("/finance/proofs/{$proof->id}/confirm", [
        'idempotency_key' => accPKey('proof'), 'lock_version' => $proof->refresh()->lock_version,
        'amount' => '5000.00', 'paid_on' => $proof->paid_on->toDateString(),
    ])->assertOk()->json();
}

function accP02ExactlyOneCollection(object $test, int $paymentId, PaymentProof $proof): void
{
    $receipt = Receipt::query()->sole();
    $allocation = MoneyJournal::query()->where('kind', 'allocation')->sole();

    expect($proof->refresh()->status)->toBe(ProofStatus::Confirmed)
        ->and($proof->payment_id)->toBe($paymentId)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Confirmed)
        ->and(Payment::query()->sole()->amount_minor)->toBe(500_000)
        ->and($receipt->payment_id)->toBe($paymentId)
        ->and($receipt->number)->toBe('RC-'.now()->format('Y').'-000001')
        ->and($receipt->amount_minor)->toBe(500_000)
        ->and(MoneyJournal::query()->where('kind', 'collection')->count())->toBe(1)
        ->and($allocation->payment_id)->toBe($paymentId)
        ->and(MoneyLedger::collected($test->order->id)->minor)->toBe(500_000)
        ->and(finDues($test->order))->toBe([
            'first' => ['amount' => 922_500, 'allocated' => 500_000],
            'final' => ['amount' => 922_500, 'allocated' => 0],
        ])
        // One receipt message to the customer; no "amount differs" explanation (same amount).
        ->and(DB::table('outbox_messages')->where('idempotency_key', 'like', 'receipt:%:sms')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('idempotency_key', 'like', 'proof:%:amount:%')->count())->toBe(0)
        ->and(StateTransition::query()->where('machine', 'M-10')->where('transition', 'T01')->count())->toBe(1);

    $confirmed = DB::table('audit_events')->where('action', 'finance.proof_confirmed')->sole();
    expect(json_decode((string) $confirmed->after, true))->toMatchArray([
        // The `proof` key is redacted by the audit recorder's sensitive-key rule; the payment link carries the fact.
        'payment' => $paymentId, 'linked_existing' => true, 'claimed_minor' => 500_000, 'received_minor' => 500_000,
    ])
        ->and($confirmed->actor_user_id)->toBe($test->f->finance->id)
        ->and(DB::table('audit_events')->where('action', 'finance.payment_recorded')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'finance.proof_submitted')->count())->toBe(1);
}

it('staff records the transfer first, the customer uploads its receipt: the proof is linked to that collection — one receipt, one allocation', function () {
    $recorded = accP02RecordTransfer($this->f, $this->order, 'FT 2610-204471');
    expect($recorded['receipt'])->toBe('RC-'.now()->format('Y').'-000001')
        ->and($recorded['finance']['summary']['collected_minor'])->toBe(500_000);

    // The customer sees the money already received and uploads the receipt of the same transfer (other formatting).
    $proof = accP02UploadProof($this, 'ft2610204471');
    expect($proof->status)->toBe(ProofStatus::UnderReview)
        ->and($proof->claimed_minor)->toBe(500_000)
        ->and($proof->payment_id)->toBeNull()
        // A proof under review is never money by itself.
        ->and(MoneyLedger::collected($this->order->id)->minor)->toBe(500_000);

    $confirmed = accP02Confirm($this->f, $proof);
    expect($confirmed['payment_id'])->toBe($recorded['payment_id'])
        ->and($confirmed['finance']['summary']['collected_minor'])->toBe(500_000);

    accP02ExactlyOneCollection($this, $recorded['payment_id'], $proof);
});

it('the customer uploads first: staff recording the same transfer is sent to the proof, whose confirmation is the one collection', function () {
    $proof = accP02UploadProof($this, 'FT2610204471');

    accPStaff($this->f->finance);
    $this->post("/orders/{$this->order->id}/finance/payments", [
        'idempotency_key' => accPKey('pay'), 'lock_version' => finVersion($this->order),
        'amount' => '5000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-2610-204471',
        'evidence' => accPPdf('bank-transfer.pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJson(['condition' => 'matching_proof']);
    expect(Payment::query()->count())->toBe(0)->and(Receipt::query()->count())->toBe(0);

    $confirmed = accP02Confirm($this->f, $proof);

    // A later staff entry of the same transfer is refused as a duplicate: still one collection.
    accPStaff($this->f->finance);
    $this->post("/orders/{$this->order->id}/finance/payments", [
        'idempotency_key' => accPKey('pay'), 'lock_version' => finVersion($this->order),
        'amount' => '5000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT 2610204471',
        'evidence' => accPPdf('bank-transfer.pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJson(['condition' => 'duplicate_reference']);

    expect(Receipt::query()->count())->toBe(1)
        ->and(MoneyJournal::query()->where('kind', 'allocation')->count())->toBe(1)
        ->and($proof->refresh()->payment_id)->toBe($confirmed['payment_id'])
        ->and(Payment::query()->sole()->origin)->toBe(Payment::ORIGIN_PROOF)
        ->and(MoneyLedger::collected($this->order->id)->minor)->toBe(500_000)
        ->and(DB::table('audit_events')->where('action', 'finance.payment_recorded')->count())->toBe(0);
});

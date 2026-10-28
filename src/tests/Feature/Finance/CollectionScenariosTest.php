<?php

/**
 * Collection parts of the SRS acceptance criteria FIN-01..06, the integration test INT-04 («تحويل واحد سجلته الإدارة
 * ورفع العميل إيصاله») and the mandatory integration scenarios 9..14 of «اختبارات ترابط ملزمة», with the documents'
 * numbers. Refunds (9, 11), commissions (10, 12) and disputes are owned by 6c/6d: here only what collections own.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Enums\ActionStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProofStatus;
use App\Finance\FinancePresenter;
use App\Finance\MoneyLedger;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Agreements\OrderAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('FIN-01: value 20,000, first due 10,000 and 4,000 collected → 16,000 left on the order and 6,000 on the first due', function () {
    finAgree($this->f, '20000');
    finPay($this->f->finance, $this->f->order, '4000');

    $money = FinancePresenter::forOrder($this->f->order->refresh(), $this->f->finance);
    $first = collect($money['dues'])->firstWhere('reference', 'first');
    expect($money['summary']['agreed_minor'])->toBe(2_000_000)
        ->and($money['summary']['collected_minor'])->toBe(400_000)
        ->and($money['summary']['remaining_minor'])->toBe(1_600_000)
        ->and($money['summary']['due_now_minor'])->toBe(600_000)
        ->and($first['amount_minor'])->toBe(1_000_000)
        ->and($first['remaining_minor'])->toBe(600_000)
        ->and($first['status'])->toBe('partially_paid');
});

it('FIN-02: a confirmed collection meets the linked stage condition at once; a customer proof under review does not', function () {
    finAgree($this->f, '20000');
    finToAgreementStage($this->f);
    $check = actionOf($this->f->order, 'first_payment');

    $proof = finProof($this->f->order, '10000');
    expect($check->refresh()->status)->not->toBe(ActionStatus::Verified)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(0);

    finConfirm($this->f->finance, $proof);
    expect($check->refresh()->status)->toBe(ActionStatus::Verified)
        ->and($check->source_reference['type'])->toBe('order_collection');
});

it('FIN-03 / scenario 10: a deposit of 600 before the agreement stays unallocated, then is allocated on agreement 1,200 without a new receipt', function () {
    $deposit = finPay($this->f->finance, $this->f->order, '600');
    expect(finDues($this->f->order))->toBe([])
        ->and(MoneyLedger::credit($this->f->order->id)->minor)->toBe(60_000)
        // The deposit never creates a price.
        ->and(OrderAgreement::value($this->f->order))->toBeNull();

    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 60_000, 'allocated' => 60_000], 'final' => ['amount' => 60_000, 'allocated' => 0]])
        ->and(MoneyLedger::credit($this->f->order->id)->minor)->toBe(0)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(Payment::query()->sole()->id)->toBe($deposit->id)
        // The 50% check of the same agreement is met by the deposit, still one collection.
        ->and(actionOf($this->f->order, 'first_payment')->source_reference)->toBe(['type' => 'order_collection', 'id' => $deposit->id]);
});

it('FIN-04: one collection of 10,000 split 7,000 and 3,000 over two orders of the customer is used once', function () {
    $other = openCustomOrder($this->f->sales, $this->f->order->customer);
    $payment = finPay($this->f->finance, $this->f->order, '10000', ['split' => [['order_id' => $other->id, 'amount' => '3000']]]);

    expect(Receipt::query()->count())->toBe(1)
        ->and(MoneyLedger::sharesOf($payment->id))->toBe([$this->f->order->id => 700_000, $other->id => 300_000])
        ->and(MoneyLedger::collected($this->f->order->id)->minor + MoneyLedger::collected($other->id)->minor)->toBe(1_000_000);
});

it('FIN-04: refuses a split above the collection or to another customer\'s order, without effect', function () {
    $foreign = openCustomOrder($this->f->owner, $this->f->inB);
    $sibling = openCustomOrder($this->f->sales, $this->f->order->customer);
    finRefused(fn () => finPay($this->f->owner, $this->f->order, '1000', ['split' => [['order_id' => $foreign->id, 'amount' => '500']]]), ValidationException::class);
    finRefused(fn () => finPay($this->f->owner, $this->f->order, '1000', ['split' => [['order_id' => $sibling->id, 'amount' => '1000']]]), ValidationException::class);
});

it('FIN-06: overpayment is held as unallocated credit; the agreement does not grow and nothing is lost', function () {
    finAgree($this->f, '20000');
    finPay($this->f->finance, $this->f->order, '25000');

    $money = FinancePresenter::forOrder($this->f->order->refresh(), $this->f->finance);
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 1_000_000, 'allocated' => 1_000_000], 'final' => ['amount' => 1_000_000, 'allocated' => 1_000_000]])
        ->and($money['summary']['credit_minor'])->toBe(500_000)
        ->and($money['summary']['excess_minor'])->toBe(500_000)
        ->and($money['summary']['remaining_minor'])->toBe(0)
        ->and(OrderAgreement::value($this->f->order)->minor)->toBe(2_000_000);
});

it('INT-04: a transfer recorded by staff and the customer\'s receipt of the same transfer are one collection, one receipt, one allocation', function () {
    finAgree($this->f, '18450');
    $payment = finPay($this->f->finance, $this->f->order, '5000', ['reference' => 'FT 2610-204471']);
    $proof = finProof($this->f->order, '5000', 'ft2610204471');

    finConfirm($this->f->finance, $proof);
    expect($proof->refresh()->status)->toBe(ProofStatus::Confirmed)
        ->and($proof->payment_id)->toBe($payment->id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(MoneyJournal::query()->where('kind', 'allocation')->count())->toBe(1)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(500_000);
});

it('INT-04: the customer\'s receipt first — staff recording the same transfer is sent to confirm the proof, then one receipt', function () {
    finAgree($this->f, '18450');
    $proof = finProof($this->f->order, '5000', 'FT2610204471');

    finRefused(fn () => finPay($this->f->finance, $this->f->order, '5000', ['reference' => 'FT2610204471']), InvalidTransition::class, 'matching_proof');
    finConfirm($this->f->finance, $proof);
    finRefused(fn () => finPay($this->f->finance, $this->f->order, '5000', ['reference' => 'FT-2610204471']), InvalidTransition::class, 'duplicate_reference');
    expect(Receipt::query()->count())->toBe(1)->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(500_000);
});

it('FIN-05: a received amount different from the claim is recorded once (the received one) and explained to the customer', function () {
    finAgree($this->f, '18450');
    $proof = finProof($this->f->order, '5000');
    finConfirm($this->f->finance, $proof, '4500');

    expect(Payment::query()->sole()->amount_minor)->toBe(450_000)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(450_000)
        ->and(Illuminate\Support\Facades\DB::table('outbox_messages')->where('idempotency_key', "proof:{$proof->id}:amount:sms")->exists())->toBeTrue();
});

it('scenario 9 (collection part): a collection of 1,000 is one payment whose refundable base is exactly its 1,000, reversible once', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');

    expect(MoneyLedger::sharesOf($payment->id))->toBe([$this->f->order->id => 100_000]);
    $collection = MoneyJournal::query()->where('payment_id', $payment->id)->where('kind', 'collection')->sole();
    $allocation = MoneyJournal::query()->where('payment_id', $payment->id)->where('kind', 'allocation')->sole();
    Illuminate\Support\Facades\DB::transaction(fn () => app(MoneyLedger::class)->reverse($allocation, 'test', $this->f->finance, 'web'));
    // A retried reversal never takes the money back twice.
    expect(fn () => Illuminate\Support\Facades\DB::transaction(fn () => app(MoneyLedger::class)->reverse($allocation, 'retry', $this->f->finance, 'web')))
        ->toThrow(LogicException::class);
    expect(MoneyLedger::credit($this->f->order->id)->minor)->toBe(100_000)
        ->and($collection->refresh()->kind->value)->toBe('collection');
});

it('scenario 11 (collection part): lowering 1,200 to 600 after collecting 900 records 300 to settle, keeps 900 collected and holds 300 as credit', function () {
    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finPay($this->f->finance, $this->f->order, '900');
    $agreement = agrApplyChange($this->f->owner, agrChange($this->f->sales, $this->f->order, [finLine('مطبخ', '500'), finLine('تركيب ونقل', '100', false)]));

    expect($agreement->settlement_needed_minor)->toBe(30_000)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(90_000)
        ->and(finDues($this->f->order))->toBe(['first' => ['amount' => 30_000, 'allocated' => 30_000], 'final' => ['amount' => 30_000, 'allocated' => 30_000]])
        ->and(MoneyLedger::credit($this->f->order->id)->minor)->toBe(30_000)
        // Never a refund or a second receipt by itself.
        ->and(Receipt::query()->count())->toBe(1);
});

it('scenario 12 (collection part): taking a payment back keeps the payment and its receipt as records', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    Illuminate\Support\Facades\DB::transaction(fn () => app(MoneyLedger::class)->reversePayment($payment, 'entry error', $this->f->finance, 'web'));

    expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe(0)
        ->and(Payment::query()->find($payment->id))->not->toBeNull()
        ->and(Receipt::query()->where('payment_id', $payment->id)->exists())->toBeTrue()
        ->and(finDues($this->f->order)['first']['allocated'])->toBe(0);
});

it('scenario 13: 50% then 100% compare the cumulative net collected of the same agreement, without consuming it or a second receipt', function () {
    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finToAgreementStage($this->f);

    $first = finPay($this->f->finance, $this->f->order, '599.99');
    expect(actionOf($this->f->order, 'first_payment')->status)->not->toBe(ActionStatus::Verified);
    $second = finPay($this->f->finance, $this->f->order, '0.01');
    expect(actionOf($this->f->order, 'first_payment')->status)->toBe(ActionStatus::Verified)
        ->and(actionOf($this->f->order, 'first_payment')->source_reference['id'])->toBe($second->id)
        ->and(actionOf($this->f->order, 'final_payment')->source_reference)->toBeNull();

    $third = finPay($this->f->finance, $this->f->order, '600');
    // The final check sits in a later stage: its truth is kept and applies when that stage opens.
    expect(actionOf($this->f->order, 'final_payment')->source_reference)->toBe(['type' => 'order_collection', 'id' => $third->id])
        ->and(Receipt::query()->count())->toBe(3)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(120_000);
    expect($first->status)->toBe(PaymentStatus::Confirmed);
});

it('scenario 14 (collection part): a value change after approval recomputes the dues once, keeps what was collected and never repeats it', function () {
    finAgree($this->f, '18450');
    finPay($this->f->finance, $this->f->order, '9225');
    expect(finDues($this->f->order)['first'])->toBe(['amount' => 922_500, 'allocated' => 922_500]);

    agrApplyChange($this->f->owner, agrChange($this->f->sales, $this->f->order, [finLine('مطبخ', '19650')]));
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 982_500, 'allocated' => 922_500], 'final' => ['amount' => 982_500, 'allocated' => 0]])
        ->and(Receipt::query()->count())->toBe(1)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(922_500);
});

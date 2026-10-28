<?php

/**
 * Collection rules (ADR-0014): the P5 stage collection condition (INT-07), allocation invariants (FIN-06), every
 * money write once (idempotency, optimistic lock), M-10 / M-11 transitions allowed vs refused without side effects,
 * scoped authorization (P-38..P-40), the schedule rules (FIN-02), gapless receipts with rollback, the reversal
 * primitive for 6c and the verified-webhook-only online path (FIN-07).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Finance\ReallocatePayment;
use App\Actions\Finance\TransferCredit;
use App\Actions\Finance\UpdateSchedule;
use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Actions\StateMachines\M10Proof\T02RequestProofCorrection;
use App\Actions\StateMachines\M10Proof\T04CancelProof;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Agreements\CustomerActor;
use App\Enums\ActionStatus;
use App\Enums\JournalKind;
use App\Enums\PaymentStatus;
use App\Enums\ProofStatus;
use App\Enums\StageStatus;
use App\Finance\CustomerProofs;
use App\Finance\Gateway\GatewayPayments;
use App\Finance\Gateway\GatewayRegistry;
use App\Finance\Gateway\InvalidGatewaySignature;
use App\Finance\Gateway\PaymentGateway;
use App\Finance\Gateway\VerifiedGatewayEvent;
use App\Finance\MoneyLedger;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\StateTransition;
use App\Money\Money;
use App\Support\StaleRecord;
use App\Workflow\RequirementsOpen;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('INT-07: the stage collection condition blocks the P5 stage until the collection is confirmed, then unblocks it', function () {
    finAgree($this->f, '20000');
    finToAgreementStage($this->f);
    $stage = stageOf($this->f->order, 'agreement');

    expectRefusedWithoutEffect(fn () => app(T04CompleteStage::class)->handle($this->f->owner, $stage->refresh()), RequirementsOpen::class, 'requirements_open');
    finProof($this->f->order, '10000');
    expectRefusedWithoutEffect(fn () => app(T04CompleteStage::class)->handle($this->f->owner, $stage->refresh()), RequirementsOpen::class, 'requirements_open');

    finPay($this->f->finance, $this->f->order, '10000', ['reference' => 'FT-OTHER-1']);
    app(T04CompleteStage::class)->handle($this->f->owner, $stage->refresh());
    expect($stage->refresh()->status)->toBe(StageStatus::Completed)
        ->and(actionOf($this->f->order, 'first_payment')->status)->toBe(ActionStatus::Verified);
});

it('keeps allocation exact: oldest due first, never above a due, the sum of entries equals the payments, moves sum to zero', function () {
    finAgree($this->f, '1000.01');
    foreach (['0.01', '333.33', '166.67', '200', '0.02', '500'] as $amount) {
        finPay($this->f->finance, $this->f->order, $amount);
        $dues = OrderDue::query()->where('order_id', $this->f->order->id)->orderBy('position')->get();
        $allocated = MoneyLedger::allocatedByDue($this->f->order->id);
        $previousFull = true;
        foreach ($dues as $due) {
            $held = $allocated[$due->id] ?? 0;
            expect($held)->toBeLessThanOrEqual($due->amount_minor);
            // Oldest first: a later due only receives money once the earlier ones are full.
            if (! $previousFull) {
                expect($held)->toBe(0);
            }
            $previousFull = $held === $due->amount_minor;
        }
        expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe((int) Payment::query()->sum('amount_minor'));
        foreach (MoneyJournal::query()->where('kind', '!=', 'collection')->get() as $journal) {
            expect((int) MoneyEntry::query()->where('journal_id', $journal->id)->sum('amount_minor'))->toBe(0);
        }
    }
    // 1,000.01 → first 500.01 (half-up), final 500.00; 1,200.03 collected → 200.02 held as credit.
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 50_001, 'allocated' => 50_001], 'final' => ['amount' => 50_000, 'allocated' => 50_000]])
        ->and(MoneyLedger::credit($this->f->order->id)->minor)->toBe(20_002);
});

it('runs a money write once per idempotency key and refuses the key with another payload or a stale version, without effect', function () {
    finAgree($this->f, '20000');
    $key = agrKey();
    $first = finPay($this->f->finance, $this->f->order, '1000', ['reference' => 'FT-IDEM-1'], $key, 'same-file');
    $again = finPay($this->f->finance, $this->f->order, '1000', ['reference' => 'FT-IDEM-1'], $key, 'same-file');
    expect($again->id)->toBe($first->id)->and(Receipt::query()->count())->toBe(1);

    finRefused(fn () => finPay($this->f->finance, $this->f->order, '2000', ['reference' => 'FT-IDEM-1'], $key, 'same-file'), ValidationException::class);
    finRefused(fn () => app(T01RecordPayment::class)->handle($this->f->finance, $this->f->order, [
        'amount' => '10', 'method' => 'cash', 'paid_on' => now()->toDateString(), 'acknowledge_similar' => true,
    ], agrPdf(), 1, agrKey()), StaleRecord::class);
});

it('refuses money writes outside the person\'s grants (P-39, P-40, P-38) without effect', function () {
    finAgree($this->f, '20000');
    $proof = finProof($this->f->order, '5000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');

    // Sales sees amounts (P-37) but never records or confirms money.
    finRefused(fn () => finPay($this->f->sales, $this->f->order, '500'), AuthorizationException::class);
    finRefused(fn () => finConfirm($this->f->sales, $proof), AuthorizationException::class);
    finRefused(fn () => app(UpdateSchedule::class)->handle($this->f->manager, $this->f->order, ['dues' => []], null, agrKey()), AuthorizationException::class);
    finRefused(fn () => app(ReallocatePayment::class)->handle($this->f->supervisor, $payment, $this->f->order, ['allocations' => []], null, agrKey()), AuthorizationException::class);
    finRefused(fn () => finPay($this->f->executor, $this->f->order, '500'), AuthorizationException::class);
});

it('M-10: allows T01/T02/T03/T04 only from their states; refused transitions change nothing', function () {
    finAgree($this->f, '20000');
    $customer = new CustomerActor($this->f->order->customer_id, $this->f->order->customer->mobile);

    $proof = finProof($this->f->order, '5000', 'FT-M10-1');
    expect(StateTransition::query()->where('machine', 'M-10')->where('transition', 'T00')->count())->toBe(1);
    app(T02RequestProofCorrection::class)->handle($this->f->finance, $proof, 'الصورة غير واضحة', $proof->lock_version);
    expect($proof->refresh()->status)->toBe(ProofStatus::NeedsCorrection);

    finRefused(fn () => finConfirm($this->f->finance, $proof), InvalidTransition::class, 'not_from_needs_correction');
    finRefused(fn () => app(T02RequestProofCorrection::class)->handle($this->f->finance, $proof->refresh(), 'x', $proof->lock_version), InvalidTransition::class, 'not_from_needs_correction');

    // T03: the customer uploads the replacement; the previous one is kept, the new one is reviewed.
    $replacement = app(CustomerProofs::class)->resubmit($customer, $proof->refresh(), ['amount' => '5000', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-M10-1'], agrPdf());
    expect($replacement->status)->toBe(ProofStatus::UnderReview)
        ->and($replacement->replaces_id)->toBe($proof->id)
        ->and($proof->refresh()->status)->toBe(ProofStatus::NeedsCorrection);
    finRefused(fn () => app(CustomerProofs::class)->resubmit($customer, $proof->refresh(), ['amount' => '5000', 'method' => 'transfer', 'paid_on' => now()->toDateString()], agrPdf()), InvalidTransition::class, 'not_from_needs_correction');
    finRefused(fn () => app(T04CancelProof::class)->handle($this->f->finance, $proof->refresh(), 'replaced', $proof->lock_version), InvalidTransition::class, 'not_from_needs_correction');

    finConfirm($this->f->finance, $replacement);
    expect($replacement->refresh()->status)->toBe(ProofStatus::Confirmed);
    finRefused(fn () => finConfirm($this->f->finance, $replacement), InvalidTransition::class, 'not_from_confirmed');
    finRefused(fn () => app(T04CancelProof::class)->handle($this->f->finance, $replacement->refresh(), 'x', $replacement->lock_version), InvalidTransition::class, 'not_from_confirmed');
    finRefused(fn () => app(CustomerProofs::class)->withdraw($customer, $replacement->refresh()), InvalidTransition::class, 'not_from_confirmed');

    // T04 by the customer on an unconfirmed proof; a stranger never acts on it.
    $other = finProof($this->f->order, '700', 'FT-M10-2');
    finRefused(fn () => app(CustomerProofs::class)->withdraw(new CustomerActor($this->f->inB->id, $this->f->inB->mobile), $other), AuthorizationException::class);
    app(CustomerProofs::class)->withdraw($customer, $other);
    expect($other->refresh()->status)->toBe(ProofStatus::Cancelled)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(500_000);
});

it('M-10-T02 keeps the file and notifies the customer; a rejection never records money', function () {
    finAgree($this->f, '20000');
    $proof = finProof($this->f->order, '5000');
    app(T02RequestProofCorrection::class)->handle($this->f->finance, $proof, 'المبلغ لا يطابق', $proof->lock_version);

    expect($proof->attachments()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('idempotency_key', "proof:{$proof->id}:correction:sms")->exists())->toBeTrue();
});

it('M-11: records T00 then T01 for staff and proof payments, with one receipt each, numbered RC-YYYY-000001…', function () {
    finAgree($this->f, '20000');
    $staff = finPay($this->f->finance, $this->f->order, '1000');
    $proof = finConfirm($this->f->finance, finProof($this->f->order, '2000', 'FT-NUM-2'));
    $year = now()->format('Y');

    expect(Receipt::query()->orderBy('id')->pluck('number')->all())->toBe(["RC-{$year}-000001", "RC-{$year}-000002"]);
    foreach ([$staff->id, $proof->payment_id] as $id) {
        expect(StateTransition::query()->where('machine', 'M-11')->where('subject_id', $id)->orderBy('id')->pluck('transition')->all())->toBe(['T00', 'T01']);
    }
});

it('gives a receipt number back when the confirming transaction rolls back (gapless)', function () {
    finAgree($this->f, '20000');
    // A failure after the number was taken (the receipt row is written, the rest of the confirmation fails).
    $fail = true;
    Receipt::created(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('outbox down');
        }
    });

    expect(fn () => finPay($this->f->finance, $this->f->order, '1000'))->toThrow(RuntimeException::class);
    expect(Receipt::query()->count())->toBe(0)->and(Payment::query()->count())->toBe(0)->and(MoneyEntry::query()->count())->toBe(0);

    $fail = false;
    finPay($this->f->finance, $this->f->order, '1000');
    expect(Receipt::query()->sole()->number)->toBe('RC-'.now()->format('Y').'-000001');
});

it('FIN-02: the schedule never exceeds the agreement, keeps paid amounts, and needs an agreement', function () {
    finRefused(fn () => app(UpdateSchedule::class)->handle($this->f->finance, $this->f->order, ['dues' => []], null, agrKey()), InvalidTransition::class, 'no_agreement');
    finAgree($this->f, '20000');
    finPay($this->f->finance, $this->f->order, '8000');
    $row = fn (string $ref, string $basis, ?string $value, string $event = 'agreement') => ['name' => ['ar' => $ref], 'reference' => $ref, 'basis' => $basis, 'value' => $value, 'event' => $event];

    finRefused(fn () => app(UpdateSchedule::class)->handle($this->f->finance, $this->f->order, ['dues' => [$row('first', 'amount', '15000'), $row('second', 'amount', '6000')]], null, agrKey()), ValidationException::class);
    finRefused(fn () => app(UpdateSchedule::class)->handle($this->f->finance, $this->f->order, ['dues' => [$row('first', 'amount', '7000'), $row('final', 'rest', null)]], null, agrKey()), ValidationException::class);

    app(UpdateSchedule::class)->handle($this->f->finance, $this->f->order, ['dues' => [
        $row('first', 'percent', '40'), $row('mid', 'amount', '5000', 'date') + ['due_date' => now()->addDays(10)->toDateString()], $row('final', 'rest', null, 'payment_stage'),
    ]], finVersion($this->f->order), agrKey());
    expect(finDues($this->f->order))->toBe([
        'first' => ['amount' => 800_000, 'allocated' => 800_000],
        'mid' => ['amount' => 500_000, 'allocated' => 0],
        'final' => ['amount' => 700_000, 'allocated' => 0],
    ]);
});

it('moves credit to another order of the same customer without a receipt, and reallocates within a payment\'s share only', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1500');
    $other = openCustomOrder($this->f->sales, $this->f->order->customer);

    app(TransferCredit::class)->handle($this->f->finance, $this->f->order, ['to_order_id' => $other->id, 'amount' => '500'], finVersion($this->f->order), agrKey());
    expect(MoneyLedger::credit($this->f->order->id)->minor)->toBe(0)
        ->and(MoneyLedger::collected($other->id)->minor)->toBe(50_000)
        ->and(Receipt::query()->count())->toBe(1);
    finRefused(fn () => app(TransferCredit::class)->handle($this->f->finance, $this->f->order, ['to_order_id' => $other->id, 'amount' => '1'], null, agrKey()), InvalidTransition::class, 'not_enough_credit');

    $dues = OrderDue::query()->where('order_id', $this->f->order->id)->orderBy('position')->pluck('id')->all();
    finRefused(fn () => app(ReallocatePayment::class)->handle($this->f->finance, $payment, $this->f->order, ['allocations' => [['due_id' => $dues[0], 'amount' => '600']]], null, agrKey()), ValidationException::class);
    app(ReallocatePayment::class)->handle($this->f->finance, $payment, $this->f->order, ['allocations' => [['due_id' => $dues[1], 'amount' => '500']]], null, agrKey());
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 50_000, 'allocated' => 0], 'final' => ['amount' => 50_000, 'allocated' => 50_000]])
        ->and(MoneyLedger::credit($this->f->order->id)->minor)->toBe(50_000);
});

it('exposes reversal as the only correction primitive: an exact negation, once, never below zero', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $collection = MoneyJournal::query()->where('payment_id', $payment->id)->where('kind', 'collection')->sole();

    // Reversing the collection while it is allocated would take money from a due twice: refused.
    expect(fn () => DB::transaction(fn () => app(MoneyLedger::class)->reverse($collection, 'x', null, 'system')))->toThrow(LogicException::class);
    $journals = DB::transaction(fn () => app(MoneyLedger::class)->reversePayment($payment, 'entry error', $this->f->finance, 'web'));
    expect($journals)->toHaveCount(2)
        ->and(collect($journals)->every(fn (MoneyJournal $j) => $j->kind === JournalKind::Reversal))->toBeTrue()
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(0)
        ->and(DB::transaction(fn () => app(MoneyLedger::class)->reversePayment($payment, 'again', null, 'system')))->toBe([]);
    expect(fn () => $collection->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => Receipt::query()->sole()->delete())->toThrow(LogicException::class);
});

/**
 * A provider stand-in: a notification is trusted only with the right HMAC signature.
 */
function finFakeGateway(): PaymentGateway
{
    return new class implements PaymentGateway
    {
        public function key(): string
        {
            return 'testpay';
        }

        public function checkoutUrl(Payment $payment, string $merchantReference, string $returnUrl): string
        {
            return 'https://pay.test/checkout/'.$merchantReference;
        }

        public function verify(Request $request): VerifiedGatewayEvent
        {
            $body = (string) $request->getContent();
            if (! hash_equals(hash_hmac('sha256', $body, 'secret'), (string) $request->header('X-Signature'))) {
                throw new InvalidGatewaySignature('bad signature');
            }
            $data = json_decode($body, true);

            return new VerifiedGatewayEvent($data['ref'], $data['tx'], $data['outcome'], $data['amount'], $data['currency']);
        }

        public function fetch(Payment $payment): ?VerifiedGatewayEvent
        {
            return null;
        }
    };
}

function finWebhook(array $data, ?string $signature = null)
{
    $body = (string) json_encode($data);

    return test()->call('POST', '/webhooks/payments/testpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'secret'),
    ], $body);
}

it('FIN-07 / FIN-03: online money is confirmed only by a verified webhook, once; the return page and a bad signature confirm nothing', function () {
    expect(finWebhook(['ref' => 'x', 'tx' => 't', 'outcome' => 'succeeded', 'amount' => 1, 'currency' => 'SAR'])->status())->toBe(404);
    app(GatewayRegistry::class)->add(finFakeGateway());
    finAgree($this->f, '1000');
    $customer = new CustomerActor($this->f->order->customer_id, $this->f->order->customer->mobile);
    ['payment' => $payment] = app(GatewayPayments::class)->begin($customer, $this->f->order, Money::of(50_000), 'testpay', 'https://shop.test/back');
    $ref = (string) $payment->reference;
    expect($payment->status)->toBe(PaymentStatus::PendingConfirmation)->and(Receipt::query()->count())->toBe(0);

    finWebhook(['ref' => $ref, 'tx' => 'T1', 'outcome' => 'succeeded', 'amount' => 50_000, 'currency' => 'SAR'], 'forged')->assertStatus(400);
    expect($payment->refresh()->status)->toBe(PaymentStatus::PendingConfirmation);

    finWebhook(['ref' => $ref, 'tx' => 'T1', 'outcome' => 'pending', 'amount' => 50_000, 'currency' => 'SAR'])->assertOk();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Unresolved);
    finWebhook(['ref' => $ref, 'tx' => 'T1', 'outcome' => 'succeeded', 'amount' => 50_000, 'currency' => 'SAR'])->assertOk();
    finWebhook(['ref' => $ref, 'tx' => 'T1', 'outcome' => 'succeeded', 'amount' => 50_000, 'currency' => 'SAR'])->assertOk();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(50_000)
        ->and(StateTransition::query()->where('machine', 'M-11')->where('subject_id', $payment->id)->pluck('transition')->all())->toBe(['T00', 'T03', 'T04', 'T08']);
});

it('FIN-07: a failed or cancelled online payment never becomes money; a late success is referred, not posted', function () {
    app(GatewayRegistry::class)->add(finFakeGateway());
    finAgree($this->f, '1000');
    $customer = new CustomerActor($this->f->order->customer_id, $this->f->order->customer->mobile);
    ['payment' => $failed] = app(GatewayPayments::class)->begin($customer, $this->f->order, Money::of(10_000), 'testpay', 'https://shop.test/back');
    ['payment' => $cancelled] = app(GatewayPayments::class)->begin($customer, $this->f->order, Money::of(20_000), 'testpay', 'https://shop.test/back');

    finWebhook(['ref' => $failed->reference, 'tx' => 'F1', 'outcome' => 'failed', 'amount' => 10_000, 'currency' => 'SAR'])->assertOk();
    finWebhook(['ref' => $cancelled->reference, 'tx' => 'C1', 'outcome' => 'cancelled', 'amount' => 20_000, 'currency' => 'SAR'])->assertOk();
    // A success for a different amount is not trusted as success.
    finWebhook(['ref' => $failed->reference, 'tx' => 'F1', 'outcome' => 'succeeded', 'amount' => 10_000, 'currency' => 'SAR'])->assertOk();

    expect($failed->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($cancelled->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and(Receipt::query()->count())->toBe(0)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(0)
        ->and(StateTransition::query()->where('subject_id', $failed->id)->where('machine', 'M-11')->latest('id')->value('reason'))->toBe('late_success');
});

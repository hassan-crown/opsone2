<?php

/**
 * Corrections of confirmed payments (FIN-08, M-11-T07): reversal + re-entry pairs with a reason and a correction
 * voucher; the ledger stays append-only and balanced, the receipt stays as issued, a stage is never un-completed.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Enums\ActionStatus;
use App\Enums\PaymentStatus;
use App\Finance\CollectionConditions;
use App\Finance\MoneyLedger;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    $this->year = now()->format('Y');
});

it('void: every effect is reversed by new journals — no row is updated or deleted — and the receipt stays as issued', function () {
    finAgree($this->f, '10000');
    $payment = finPay($this->f->finance, $this->f->order, '5000');
    $rows = DB::table('money_entries')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
    $journals = MoneyJournal::query()->count();

    $correction = setCorrect($this->f->finance, $this->f->order, $payment, ['kind' => 'void']);

    expect($correction->number)->toBe("CR-{$this->year}-000001")
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Corrected)
        ->and(Receipt::query()->where('payment_id', $payment->id)->value('amount_minor'))->toBe(500_000)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(0)
        ->and(finDues($this->f->order)['first']['allocated'])->toBe(0)
        // The old rows are untouched; the reversal journals were appended.
        ->and(DB::table('money_entries')->orderBy('id')->limit(count($rows))->get()->map(fn ($e) => (array) $e)->all())->toBe($rows)
        ->and(MoneyJournal::query()->count())->toBe($journals * 2)
        ->and((int) MoneyEntry::query()->sum('amount_minor'))->toBe(0);
    setLedgerBalanced();
});

it('amend: the reversal and the re-entry form one pair — one new receipt for the right amount, the balance follows it', function () {
    finAgree($this->f, '10000');
    $payment = finPay($this->f->finance, $this->f->order, '5000', ['reference' => 'FT-1188']);

    $correction = setCorrect($this->f->finance, $this->f->order, $payment, [
        'kind' => 'amend', 'amount' => '4500', 'method' => 'transfer', 'paid_on' => $payment->paid_on->toDateString(), 'reference' => 'FT-1188',
    ]);
    $replacement = Payment::query()->findOrFail($correction->replacement_payment_id);

    expect($replacement->status)->toBe(PaymentStatus::Confirmed)
        ->and($replacement->amount_minor)->toBe(450_000)
        ->and($replacement->receipt?->number)->toBe("RC-{$this->year}-000002")
        ->and(Receipt::query()->count())->toBe(2)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(450_000)
        ->and(finDues($this->f->order)['first'])->toBe(['amount' => 500_000, 'allocated' => 450_000])
        ->and($correction->before['amount_minor'])->toBe(500_000)
        ->and($correction->after['amount_minor'] ?? null)->toBe(450_000);
    setLedgerBalanced();
});

it('INT-07: correcting a payment never un-completes a verified stage; the shortfall is listed', function () {
    finAgree($this->f, '1000');
    finToAgreementStage($this->f);
    $payment = finPay($this->f->finance, $this->f->order, '500');
    expect(actionOf($this->f->order, 'first_payment')->status)->toBe(ActionStatus::Verified);

    setCorrect($this->f->finance, $this->f->order, $payment, ['kind' => 'void']);

    expect(actionOf($this->f->order, 'first_payment')->status)->toBe(ActionStatus::Verified)
        ->and(CollectionConditions::shortfalls($this->f->order->refresh()))->not->toBeEmpty();
});

it('refuses a correction of a payment with a refund, of an already corrected payment, without P-41, or with nothing changed — without effect', function () {
    finAgree($this->f, '10000');
    $refunded = finPay($this->f->finance, $this->f->order, '1000');
    setRefund($this->f->finance, $this->f->order, $refunded, '100');
    setRefused(fn () => setCorrect($this->f->finance, $this->f->order, $refunded, ['kind' => 'void']), InvalidTransition::class, 'payment_has_refunds');

    $payment = finPay($this->f->finance, $this->f->order, '2000', ['reference' => 'FT-SAME']);
    setRefused(fn () => setCorrect($this->f->manager, $this->f->order, $payment, ['kind' => 'void']), AuthorizationException::class);
    setRefused(fn () => setCorrect($this->f->finance, $this->f->order, $payment, [
        'kind' => 'amend', 'amount' => '2000', 'method' => 'transfer', 'paid_on' => $payment->paid_on->toDateString(), 'reference' => 'FT-SAME',
    ]), InvalidTransition::class, 'nothing_changed');

    setCorrect($this->f->finance, $this->f->order, $payment, ['kind' => 'void']);
    setRefused(fn () => setCorrect($this->f->finance, $this->f->order, $payment->refresh(), ['kind' => 'void']), InvalidTransition::class, 'not_from_corrected');
});

it('an amendment of money split over two orders is refused (void and record again); a void reverses both orders', function () {
    finAgree($this->f, '10000');
    $other = openCustomOrder($this->f->sales, $this->f->inA);
    $payment = finPay($this->f->finance, $this->f->order, '10000', ['split' => [['order_id' => $other->id, 'amount' => '3000']]]);

    setRefused(fn () => setCorrect($this->f->finance, $this->f->order, $payment, [
        'kind' => 'amend', 'amount' => '9000', 'method' => 'cash', 'paid_on' => $payment->paid_on->toDateString(),
    ]), InvalidTransition::class, 'split_payment');

    setCorrect($this->f->finance, $this->f->order, $payment, ['kind' => 'void']);
    expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe(0)
        ->and(MoneyLedger::collected($other->id)->minor)->toBe(0);
    setLedgerBalanced();
});

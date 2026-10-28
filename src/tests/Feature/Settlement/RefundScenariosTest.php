<?php

/**
 * Refund parts of the SRS (FIN-05, FIN-09, INT-05, INT-07) and of the mandatory integration scenarios of «اختبارات ترابط
 * ملزمة»: 9 («ردان300 و800 من قابل للرد1000»), 11 (lowering the agreement to 600 with a refund of 300 due) and 12 (a
 * refund after money was counted — the refund side: the payment and receipt kept, one effect announced to 6d), with
 * the voucher numbers RF-YYYY-000123.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M12Refund\RefundProviderResults;
use App\Actions\StateMachines\M12Refund\T01StartRefund;
use App\Actions\StateMachines\M12Refund\T02ConfirmRefund;
use App\Actions\StateMachines\M12Refund\T04RecordRefundFailure;
use App\Actions\StateMachines\M12Refund\T07RetryRefund;
use App\Agreements\OrderAgreement;
use App\Enums\ActionStatus;
use App\Enums\RefundStatus;
use App\Finance\CollectionConditions;
use App\Finance\FinanceWorkList;
use App\Finance\MoneyLedger;
use App\Finance\Settlement\RefundExecuted;
use App\Finance\Settlement\RefundLimits;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Finance\Refund;
use App\Models\StateTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    $this->year = now()->format('Y');
});

it('scenario 9: with 1,000 refundable, a request of 300 is reserved and a second of 800 is refused; unknown keeps the reserve, a final failure releases it', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    expect(RefundLimits::refundable($payment, $this->f->order->id)->minor)->toBe(100_000);

    $first = setRefund($this->f->sales, $this->f->order, $payment, '300');
    // The request alone reduces nothing (FIN-09), but reserves its 300.
    expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe(100_000)
        ->and(RefundLimits::refundable($payment, $this->f->order->id)->minor)->toBe(70_000);

    setRefused(fn () => setRefund($this->f->finance, $this->f->order, $payment, '800'), InvalidTransition::class, 'not_refundable');

    // Sent, then the result is unknown: the 300 stays reserved and 800 is still refused.
    app(T01StartRefund::class)->handle($this->f->finance, $first, ['reference' => 'TRX-300'], $first->refresh()->lock_version, agrKey());
    app(RefundProviderResults::class)->unresolved($first, 'TRX-300');
    expect($first->refresh()->status)->toBe(RefundStatus::Unresolved);
    setRefused(fn () => setRefund($this->f->finance, $this->f->order, $payment, '800'), InvalidTransition::class, 'not_refundable');

    // A final failure releases the limit: 800 now fits; nothing was refunded.
    app(T04RecordRefundFailure::class)->handle(null, $first, 'رفض البنك التحويل.', null, null);
    expect($first->refresh()->status)->toBe(RefundStatus::Failed)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(100_000);
    $second = setRefund($this->f->finance, $this->f->order, $payment, '800');
    expect(RefundLimits::refundable($payment, $this->f->order->id)->minor)->toBe(20_000);

    // A retry of the failed 300 re-checks the limit: 300 no longer fits next to 800.
    setRefused(fn () => app(T07RetryRefund::class)->handle($this->f->finance, $first->refresh(), [], $first->lock_version, agrKey()),
        InvalidTransition::class, 'not_refundable');

    $paid = setPaid($this->f->finance, $second, 'TRX-800');
    expect($paid->number)->toBe("RF-{$this->year}-000001")
        ->and($paid->status)->toBe(RefundStatus::Executed)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(20_000)
        ->and(RefundLimits::refundable($payment, $this->f->order->id)->minor)->toBe(20_000);
    setLedgerBalanced();
});

it('FIN-05: a confirmed refund retried with the same idempotency key reduces the net collected once and issues one voucher', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $refund = setRefund($this->f->finance, $this->f->order, $payment, '450');
    $key = agrKey();
    $version = $refund->refresh()->lock_version;
    $file = agrPdf('refund.pdf', 'same');

    $once = app(T02ConfirmRefund::class)->handle($this->f->finance, $refund, ['reference' => 'TRX-450'], $file, $version, $key);
    $twice = app(T02ConfirmRefund::class)->handle($this->f->finance, $refund, ['reference' => 'TRX-450'], $file, $version, $key);

    expect($once->number)->toBe($twice->number)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(55_000)
        ->and(MoneyJournal::query()->where('kind', 'refund')->count())->toBe(1)
        ->and(Refund::query()->whereNotNull('number')->count())->toBe(1);
    // Requested → in progress → executed, one transition each.
    expect(StateTransition::query()->where('machine', 'M-12')->orderBy('id')->pluck('transition')->all())->toBe(['T00', 'T01', 'T02']);
});

it('a refund takes the payment\'s credit first, then its latest allocation (newest due first), and never un-completes a stage', function () {
    finAgree($this->f, '1000');
    finToAgreementStage($this->f);
    $payment = finPay($this->f->finance, $this->f->order, '1200');
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 50_000, 'allocated' => 50_000], 'final' => ['amount' => 50_000, 'allocated' => 50_000]])
        ->and(actionOf($this->f->order, 'first_payment')->status)->toBe(ActionStatus::Verified);

    setPaid($this->f->finance, setRefund($this->f->finance, $this->f->order, $payment, '200'));
    expect(MoneyLedger::credit($this->f->order->id)->minor)->toBe(0)
        ->and(finDues($this->f->order)['final']['allocated'])->toBe(50_000);

    setPaid($this->f->finance, setRefund($this->f->finance, $this->f->order, $payment, '800'));
    expect(finDues($this->f->order))->toBe(['first' => ['amount' => 50_000, 'allocated' => 20_000], 'final' => ['amount' => 50_000, 'allocated' => 0]])
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(20_000)
        // INT-07: the verified check stays; the shortfall is shown to the authorized person.
        ->and(actionOf($this->f->order, 'first_payment')->status)->toBe(ActionStatus::Verified)
        ->and(CollectionConditions::shortfalls($this->f->order->refresh()))->not->toBeEmpty();
    setLedgerBalanced();
});

it('scenario 11 (refund part): agreement 1,200 lowered to 600 after 600 collected with 300 to refund — after the refund the net collected is 300, once', function () {
    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    $payment = finPay($this->f->finance, $this->f->order, '600');
    agrApplyChange($this->f->owner, agrChange($this->f->sales, $this->f->order, [finLine('مطبخ', '500'), finLine('تركيب ونقل', '100', false)]));
    expect(OrderAgreement::value($this->f->order)?->minor)->toBe(60_000)
        ->and(OrderAgreement::productsValue($this->f->order)?->minor)->toBe(50_000);

    $refund = setPaid($this->f->finance, setRefund($this->f->sales, $this->f->order, $payment, '300'));

    expect($refund->number)->toBe("RF-{$this->year}-000001")
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(30_000)
        // The agreement is lowered once by its change; the money once by the refund (INT-05).
        ->and(OrderAgreement::value($this->f->order)?->minor)->toBe(60_000)
        ->and(Receipt::query()->count())->toBe(1);
    setLedgerBalanced();
});

it('scenario 12 (refund part): a refund keeps the payment and its receipt and announces one effect after commit for commissions', function () {
    Event::fake([RefundExecuted::class]);
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $refund = setPaid($this->f->finance, setRefund($this->f->finance, $this->f->order, $payment, '250'));

    Event::assertDispatchedTimes(RefundExecuted::class, 1);
    Event::assertDispatched(RefundExecuted::class, fn (RefundExecuted $e) => $e->refundId === $refund->id && $e->amountMinor === 25_000
        && $e->number === "RF-{$this->year}-000001" && $e->orderId === $this->f->order->id);
    expect(Payment::query()->find($payment->id)?->amount_minor)->toBe(100_000)
        ->and(Receipt::query()->where('payment_id', $payment->id)->value('amount_minor'))->toBe(100_000);
});

it('refuses a refund above the refundable balance, of an unconfirmed or foreign payment, or by someone without P-42 — without effect', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $other = openCustomOrder($this->f->sales, $this->f->inA);
    finAgree((object) [...(array) $this->f, 'order' => $other], '500');
    $foreign = finPay($this->f->finance, $other, '500');

    setRefused(fn () => setRefund($this->f->finance, $this->f->order, $payment, '1000.01'), InvalidTransition::class, 'not_refundable');
    setRefused(fn () => setRefund($this->f->finance, $this->f->order, $foreign, '10'), InvalidTransition::class, 'payment_not_on_order');
    setRefused(fn () => setRefund($this->f->executor, $this->f->order, $payment, '10'), AuthorizationException::class);
    setRefused(fn () => setRefund($this->f->supervisor, $this->f->order, $payment, '10'), AuthorizationException::class);
});

it('FIN-11: refunds not yet executed join the finance work list', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $refund = setRefund($this->f->finance, $this->f->order, $payment, '100');

    $list = FinanceWorkList::for($this->f->finance);
    expect($list['counts']['refund'])->toBe(1)
        ->and(collect($list['items'])->firstWhere('kind', 'refund')['refund_id'])->toBe($refund->id);
});

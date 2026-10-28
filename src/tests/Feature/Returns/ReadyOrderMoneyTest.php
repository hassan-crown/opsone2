<?php

/**
 * Regression for the P6 change of ADR-0019: a ready order takes money only on the manual path — a staff-recorded
 * payment (M-11-T01, P-39) and staff refunds (M-12, P-42 request / P-43 execution) — with the same grants, reserved
 * limit and idempotency as a made-to-order order. Online payments, customer proofs, provider refund results and every
 * other money write still refuse ready orders (until P8's store checkout); made-to-order finance is unchanged.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Finance\TransferCredit;
use App\Actions\StateMachines\M12Refund\RefundProviderResults;
use App\Actions\StateMachines\M12Refund\T01StartRefund;
use App\Actions\StateMachines\M12Refund\T02ConfirmRefund;
use App\Agreements\CustomerActor;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Finance\CustomerProofs;
use App\Finance\Gateway\GatewayPayments;
use App\Finance\Gateway\GatewayRegistry;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReturnTestHelpers.php';
require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = returnFixture(10, 5, 5);
    $this->order = $this->f->order->refresh();
    $this->finance = $this->f->org->member('finance', [$this->f->org->a]);
    $this->executor = $this->f->org->member('executor', [$this->f->org->a]);
});

it('records a counter payment on a ready order with P-39 only, once per key, as credit', function () {
    $key = rtKey();
    $first = finPay($this->finance, $this->order, '100', ['reference' => 'FT-READY-0001'], $key, 'counter');
    $again = finPay($this->finance, $this->order, '100', ['reference' => 'FT-READY-0001'], $key, 'counter');

    expect($again->id)->toBe($first->id)
        ->and(Payment::query()->where('order_id', $this->order->id)->count())->toBe(1)
        ->and($first->status)->toBe(PaymentStatus::Confirmed)
        ->and($first->receipt?->number)->toStartWith('RC-');
    setRefused(fn () => finPay($this->executor, $this->order, '50'), AuthorizationException::class);
    setRefused(fn () => finPay($this->f->keeper, $this->order, '50'), AuthorizationException::class);
});

it('refunds a ready order only within the reserved limit, with P-42 to ask and P-43 to execute (scenario 9 on ready)', function () {
    $payment = finPay($this->finance, $this->order, '1000');

    setRefused(fn () => setRefund($this->executor, $this->order, $payment, '300'), AuthorizationException::class);
    $first = setRefund($this->f->manager, $this->order, $payment, '300');
    setRefused(fn () => setRefund($this->f->manager, $this->order, $payment, '800'), InvalidTransition::class, 'not_refundable');
    expect(setRefund($this->f->manager, $this->order, $payment, '700')->status)->toBe(RefundStatus::Requested);

    // P-43 executes; the manager (P-42 without P-43) cannot.
    setRefused(fn () => setPaid($this->f->manager, $first), AuthorizationException::class);
    $key = rtKey();
    $version = $first->refresh()->lock_version;
    $confirm = fn () => app(T02ConfirmRefund::class)->handle($this->finance, $first->refresh(), ['reference' => 'TRX-READY-1'], agrPdf('refund.pdf', 'same'), $version, $key);
    $paid = $confirm();
    expect($confirm()->id)->toBe($paid->id)
        ->and($paid->status)->toBe(RefundStatus::Executed)
        ->and(Refund::query()->where('status', 'executed')->count())->toBe(1);
});

it('still refuses online and other money paths on a ready order', function () {
    $payment = finPay($this->finance, $this->order, '500');
    $other = readyOrder($this->f->s, 1);
    $customer = new CustomerActor($this->order->customer_id, $this->order->customer->mobile);
    app(GatewayRegistry::class)->add(readyGateway());

    setRefused(fn () => app(GatewayPayments::class)->begin($customer, $this->order, Money::of(5000), 'readypay', 'https://shop.test/back'), InvalidTransition::class, 'ready_order');
    setRefused(fn () => app(CustomerProofs::class)->submit($customer, $this->order, [
        'amount' => '50', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-READY-1',
    ], agrPdf('receipt.pdf', 'r')), InvalidTransition::class, 'ready_order');
    setRefused(fn () => app(TransferCredit::class)->handle($this->finance, $this->order, ['to_order_id' => $other->id, 'amount' => '10'], null, rtKey()), InvalidTransition::class, 'ready_order');
    setRefused(fn () => setCorrect($this->finance, $this->order, $payment, ['kind' => 'void']), InvalidTransition::class, 'ready_order');
    setRefused(fn () => setDispute($this->finance, $this->order, []), InvalidTransition::class, 'ready_order');

    // Provider refund results (online refunds) stay closed for ready orders.
    $refund = setRefund($this->f->manager, $this->order, $payment, '100');
    $started = app(T01StartRefund::class)->handle($this->finance, $refund->refresh(), [], $refund->lock_version, rtKey());
    setRefused(fn () => app(RefundProviderResults::class)->succeeded($started, 'PROV-1'), InvalidTransition::class, 'ready_order');
    setRefused(fn () => app(RefundProviderResults::class)->unresolved($started, 'PROV-1'), InvalidTransition::class, 'ready_order');
});

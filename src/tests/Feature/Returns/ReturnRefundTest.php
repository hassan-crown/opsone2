<?php

/**
 * The money side of a return (INT-05, P04 → P05): one refund through the P6 refund flow (M-12), asked by P-42 for at
 * most the settlement fixed at acceptance and reserved from the payment's refundable limit; executed only by P-43 with
 * proof. A replay asks nothing twice; a second live refund is refused; a failed one may be asked again. The
 * commission hold stays while the return's money is open.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Returns\RequestReturnRefund;
use App\Actions\StateMachines\M12Refund\T01StartRefund;
use App\Actions\StateMachines\M12Refund\T04RecordRefundFailure;
use App\Commissions\SettlementHolds;
use App\Enums\RefundStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Returns\OrderReturn;
use App\Models\User;
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
    // The customer paid the ready order at the counter (M-11 credit on the order).
    $this->payment = finPay($this->f->owner, $this->f->order, Money::of((int) $this->f->order->refresh()->total_minor)->toInput());
    $this->return = rtRequest($this->f->keeper, $this->f->order, $this->f->line, '2');
    rtAccept($this->f->manager, $this->return);
    $this->return->refresh();
});

function rtRefund(User $actor, OrderReturn $return, Payment $payment, int $amountMinor, ?string $key = null): Refund
{
    $return->refresh();

    return app(RequestReturnRefund::class)->handle($actor, $return, [
        'payment_id' => $payment->id, 'amount_minor' => $amountMinor, 'method' => 'transfer', 'reason' => 'رد قيمة المرتجع',
    ], $return->lock_version, $key ?? rtKey());
}

it('asks one M-12 refund for the settlement, reserving it from the payment, and replays it once', function () {
    $f = $this->f;
    $settlement = (int) $this->return->settlement_minor;
    $key = rtKey();
    $version = $this->return->lock_version;
    $first = app(RequestReturnRefund::class)->handle($f->manager, $this->return, ['payment_id' => $this->payment->id, 'amount_minor' => $settlement, 'method' => 'transfer', 'reason' => 'رد قيمة المرتجع'], $version, $key);
    $again = app(RequestReturnRefund::class)->handle($f->manager, $this->return->refresh(), ['payment_id' => $this->payment->id, 'amount_minor' => $settlement, 'method' => 'transfer', 'reason' => 'رد قيمة المرتجع'], $version, $key);

    expect($again->id)->toBe($first->id)
        ->and(Refund::query()->count())->toBe(1)
        ->and($first->status)->toBe(RefundStatus::Requested)
        ->and($first->amount_minor)->toBe($settlement)
        ->and($first->reason)->toStartWith($this->return->number)
        ->and($this->return->refresh()->refund_id)->toBe($first->id)
        // The request reserves; nothing left the ledger.
        ->and(app(SettlementHolds::class)->holdOf($f->order->refresh()))->toBe('refund');

    // A second live refund for the same return is refused without effect.
    rtRefused(fn () => rtRefund($f->manager, $this->return, $this->payment, 100), InvalidTransition::class, 'refund_exists');

    // Executed by P-43 with proof (M-12-T02): RF number, and still only one refund.
    $paid = setPaid($f->owner, $first);
    expect($paid->status)->toBe(RefundStatus::Executed)->and($paid->number)->toStartWith('RF-')
        ->and(Refund::query()->count())->toBe(1);
    rtRefused(fn () => rtRefund($f->manager, $this->return, $this->payment, 100), InvalidTransition::class, 'refund_exists');
});

it('limits the refund by the return settlement and by the payment refundable limit', function () {
    $f = $this->f;
    $settlement = (int) $this->return->settlement_minor;
    rtRefused(fn () => rtRefund($f->manager, $this->return, $this->payment, $settlement + 1), InvalidTransition::class, 'above_settlement');

    // Another refund already reserves most of the payment: the return's refund no longer fits (M-12 not_refundable).
    $total = (int) $f->order->total_minor;
    setRefund($f->owner, $f->order, $this->payment, Money::of($total - $settlement + 1)->toInput());
    rtRefused(fn () => rtRefund($f->manager, $this->return, $this->payment, $settlement), InvalidTransition::class, 'not_refundable');
    expect(rtRefund($f->manager, $this->return, $this->payment, $settlement - 1)->amount_minor)->toBe($settlement - 1);
});

it('needs P-42 and an accepted return with a settlement', function () {
    $f = $this->f;
    rtRefused(fn () => rtRefund($f->keeper, $this->return, $this->payment, 100), AuthorizationException::class);

    $requested = rtRequest($f->keeper, $f->order, $f->line, '1');
    rtRefused(fn () => rtRefund($f->manager, $requested, $this->payment, 100), InvalidTransition::class, 'no_settlement');

    $free = rtRequest($f->keeper, $f->order, $f->line, '1');
    rtAccept($f->manager, $free, null, 0);
    rtRefused(fn () => rtRefund($f->manager, $free, $this->payment, 100), InvalidTransition::class, 'no_settlement');
});

it('lets a failed refund be asked again, never two at once', function () {
    $f = $this->f;
    $refund = rtRefund($f->manager, $this->return, $this->payment, 1000);
    $started = app(T01StartRefund::class)->handle($f->owner, $refund->refresh(), [], $refund->lock_version, rtKey());
    app(T04RecordRefundFailure::class)->handle($f->owner, $started, 'رفض البنك التحويل', $started->lock_version, rtKey());

    $second = rtRefund($f->manager, $this->return, $this->payment, 1000);
    expect($second->id)->not->toBe($refund->id)->and($this->return->refresh()->refund_id)->toBe($second->id)
        ->and(Refund::query()->where('status', 'requested')->count())->toBe(1);
});

it('holds the order commission while a return is open and releases it once the money is asked', function () {
    $f = $this->f;
    $holds = app(SettlementHolds::class);
    expect($holds->holdOf($f->order->refresh()))->toBe('settlement');

    rtReceive($f->keeper, $this->return, $f->location, '2', ['0', '2', '0']);
    // Damaged goods do not decide the money: the settlement still stands and is still open.
    expect($this->return->refresh()->settlement_minor)->toBeGreaterThan(0)
        ->and($holds->holdOf($f->order->refresh()))->toBe('settlement');

    $refund = rtRefund($f->manager, $this->return, $this->payment, (int) $this->return->settlement_minor);
    setPaid($f->owner, $refund);
    expect($holds->holdOf($f->order->refresh()))->toBeNull();
});

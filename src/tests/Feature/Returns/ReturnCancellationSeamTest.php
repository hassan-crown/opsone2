<?php

/**
 * The P7 merge seam: returns and cancellations feed the order's one settlement follow-up. A return whose money needs
 * settling adds a `return` item followed from the return (done when its refund is executed, dropped when it ends
 * without money); a cancellation's refund suggestion never counts money a return pays back, and a return refund never
 * settles the cancellation's refund item (and vice versa).
 */

use App\Actions\Returns\RequestReturnRefund;
use App\Actions\StateMachines\M15Return\T03WithdrawReturn;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Returns\OrderReturn;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Money\Money;
use App\Returns\ReturnEligibility;
use App\Settlement\SettlementFollowUps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReturnTestHelpers.php';
require_once __DIR__.'/../Cancellation/CancellationTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
});

function seamRefund(User $actor, OrderReturn $return, Payment $payment): Refund
{
    $return->refresh();

    return app(RequestReturnRefund::class)->handle($actor, $return, [
        'payment_id' => $payment->id, 'amount_minor' => (int) $return->settlement_minor, 'method' => 'transfer', 'reason' => 'رد قيمة المرتجع',
    ], $return->lock_version, rtKey());
}

function seamItem(OrderReturn $return): SettlementItem
{
    return SettlementItem::query()->where('source', 'return:'.$return->id)->sole();
}

it('adds one return item to the follow-up and settles it when the return refund is executed', function () {
    $f = returnFixture(10, 5, 5);
    $payment = finPay($f->owner, $f->order, Money::of((int) $f->order->refresh()->total_minor)->toInput());
    $return = rtAccept($f->manager, rtRequest($f->keeper, $f->order, $f->line, '2'));

    $item = seamItem($return);
    expect($item->kind)->toBe(SettlementItem::RETURN)->and($item->status)->toBe('open')
        ->and($item->target_minor)->toBe((int) $return->settlement_minor)
        ->and(cnFollowUp($f->order)?->status)->toBe('open');

    // Inspection asks again: still one item (idempotent per return).
    rtReceive($f->keeper, $return, $f->location, '2', ['2', '0', '0']);
    expect(SettlementItem::query()->where('source', 'return:'.$return->id)->count())->toBe(1);

    // The refund request alone settles nothing; the executed refund does.
    $refund = seamRefund($f->manager, $return, $payment);
    expect(seamItem($return)->status)->toBe('open');
    setPaid($f->owner, $refund);
    expect(seamItem($return)->status)->toBe('done')->and(seamItem($return)->resolution)->toBe('auto')
        ->and(cnFollowUp($f->order)?->status)->toBe('closed');
});

it('drops the return item when the return is withdrawn before receipt and lowers it on a partial release', function () {
    $f = returnFixture(10, 5, 5);
    $return = rtAccept($f->manager, rtRequest($f->keeper, $f->order, $f->line, '2'));
    $full = (int) $return->settlement_minor;

    // One of two received, the other released: the target follows the lower settlement.
    rtReceive($f->keeper, $return, $f->location, '1');
    $return->refresh();
    app(T03WithdrawReturn::class)->handle($f->manager, $return, 'العميل احتفظ بالقطعة الثانية', $return->lock_version, rtKey());
    expect(seamItem($return)->status)->toBe('open')
        ->and(seamItem($return)->target_minor)->toBe((int) $return->refresh()->settlement_minor)
        ->and(seamItem($return)->target_minor)->toBeLessThan($full);

    $other = rtAccept($f->manager, rtRequest($f->keeper, $f->order, $f->line, '1'));
    app(T03WithdrawReturn::class)->handle($f->manager, $other->refresh(), 'تراجع العميل', $other->lock_version, rtKey());
    expect($other->refresh()->status->value)->toBe('cancelled')->and(seamItem($other)->status)->toBe('dropped');
});

it('lets P-42 settle a return item otherwise, never P-43 alone', function () {
    expect(SettlementFollowUps::permissionFor(SettlementItem::RETURN)->value)->toBe('P-42');
});

it('suggests the cancelled value as refund on a paid ready order, apart from a return of delivered goods', function () {
    // 5 ordered and paid, 2 delivered and asked back, the 3 not delivered cancelled.
    $f = returnFixture(10, 5, 2);
    $order = $f->order->refresh();
    $payment = finPay($f->owner, $order, Money::of((int) $order->total_minor)->toInput());
    $unit = intdiv((int) $order->total_minor, 5);
    $return = rtAccept($f->manager, rtRequest($f->keeper, $order, $f->line, '2'));
    expect((int) $return->settlement_minor)->toBe(2 * $unit);

    // Return refund asked first: it is neither «pending» nor «kept» for the cancellation.
    $returnRefund = seamRefund($f->manager, $return, $payment);
    $preview = cnPreview($order, ['scope' => 'full']);
    expect($preview['settlement']['money']['collected_minor'])->toBe((int) $order->total_minor)
        ->and($preview['settlement']['money']['pending_refunds_minor'])->toBe(0)
        ->and($preview['settlement']['money']['refund_minor'])->toBe(3 * $unit);

    cnCancel($f->owner, $order, ['scope' => 'full']);
    $cancelRefund = SettlementItem::query()->where('order_id', $order->id)->where('kind', SettlementItem::REFUND)->sole();
    expect($cancelRefund->target_minor)->toBe(3 * $unit)->and(seamItem($return)->status)->toBe('open');

    // The return refund settles only the return item.
    setPaid($f->owner, $returnRefund);
    expect(seamItem($return)->status)->toBe('done')->and($cancelRefund->refresh()->status)->toBe('open')
        ->and(cnFollowUp($order)?->status)->toBe('open');

    // The cancellation refund closes its own item; together they never exceed what was paid.
    setPaid($f->owner, setRefund($f->owner, $order->refresh(), $payment, Money::of(3 * $unit)->toInput()));
    expect($cancelRefund->refresh()->status)->toBe('done')->and(cnFollowUp($order)?->status)->toBe('closed')
        ->and((int) Refund::query()->where('order_id', $order->id)->sum('amount_minor'))->toBe((int) $payment->amount_minor);
    setLedgerBalanced();
});

it('keeps the cancellation refund the same when the return refund was already executed', function () {
    $f = returnFixture(10, 5, 2);
    $order = $f->order->refresh();
    $payment = finPay($f->owner, $order, Money::of((int) $order->total_minor)->toInput());
    $unit = intdiv((int) $order->total_minor, 5);
    $return = rtAccept($f->manager, rtRequest($f->keeper, $order, $f->line, '2'));
    setPaid($f->owner, seamRefund($f->manager, $return, $payment));

    $preview = cnPreview($order, ['scope' => 'partial', 'lines' => [['ref' => 'l'.$f->line, 'quantity' => '1']]]);
    expect($preview['settlement']['money']['refund_minor'])->toBe($unit);
});

it('lets a cancellation of the undelivered rest leave an open return untouched, and the return go on', function () {
    $f = returnFixture(10, 5, 2);
    $return = rtAccept($f->manager, rtRequest($f->keeper, $f->order, $f->line, '1'));

    cnCancel($f->owner, $f->order, ['scope' => 'full']);
    expect($f->order->refresh()->status)->toBe('delivered')
        ->and(ReturnEligibility::lines($f->order)[$f->line]['eligible'])->toBe(1000);

    // Receipt and inspection still run on the delivered (and partly cancelled) order.
    rtReceive($f->keeper, $return, $f->location, '1', ['1', '0', '0']);
    expect($return->refresh()->status->value)->toBe('inspected');
    stockJournalMatches();
});

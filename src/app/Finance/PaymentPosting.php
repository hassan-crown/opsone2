<?php

namespace App\Finance;

use App\Access\TransitionRecorder;
use App\Enums\PaymentStatus;
use App\Enums\StateMachine;
use App\Models\Finance\OrderFinance;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use LogicException;

/**
 * The one place where money becomes real (M-11 → confirmed), whichever channel reported it — staff recording,
 * a confirmed customer proof or a verified gateway event: exactly one receipt (unique per payment, gapless number),
 * one collection journal over the orders' shares, allocation oldest due first, the stage collection conditions
 * (INT-07) and the outbox messages — all in the caller's transaction, under the orders' locks.
 */
final class PaymentPosting
{
    public function __construct(
        private readonly MoneyLedger $ledger,
        private readonly Allocator $allocator,
        private readonly CollectionConditions $conditions,
        private readonly ReceiptNumbers $numbers,
        private readonly CollectionNotices $notices,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array<int, Money>  $shares  order id → part; sums to the payment
     * @param  array<int, Order>  $orders  the locked orders of the shares
     * @param  array<int, OrderFinance>  $heads  their finance heads
     * @param  array<string, mixed>  $context
     */
    public function confirm(Payment $payment, string $transition, array $shares, array $orders, array $heads, ?User $actor, string $source, array $context = []): Receipt
    {
        $from = $payment->status;
        $allowed = PaymentStatus::transitions()[$transition] ?? throw new LogicException("Unknown M-11 transition {$transition}.");
        if (! in_array($from, $allowed['from'], true) || ! in_array(PaymentStatus::Confirmed, $allowed['to'], true)) {
            throw new LogicException("M-11-{$transition} does not confirm from {$from->value}.");
        }
        if (Money::sum($shares)->minor !== $payment->amount_minor) {
            throw new LogicException('The shares must sum to the payment.');
        }

        $payment->forceFill(['status' => PaymentStatus::Confirmed, 'confirmed_by' => $actor?->id, 'confirmed_at' => now()])->save();
        $this->transitions->record(StateMachine::M11, $transition, $payment, $from, PaymentStatus::Confirmed, $actor, $source, null, $context);

        $receipt = Receipt::query()->create([
            'number' => $this->numbers->next(),
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'order_id' => $payment->order_id,
            'amount_minor' => $payment->amount_minor,
            'issued_by' => $actor?->id,
            'issued_at' => now(),
        ]);
        $this->ledger->collect($payment, $shares, $actor, $source);

        foreach (array_keys($shares) as $orderId) {
            $order = $orders[$orderId] ?? throw new LogicException('A share order was not locked.');
            $this->allocator->settle($order, $actor, $source);
            $this->conditions->evaluate($order, $payment->id, $actor);
            FinanceLock::touch($heads[$orderId] ?? throw new LogicException('A share order has no finance head.'));
        }

        $this->notices->receiptIssued($receipt, $orders[$payment->order_id] ?? $payment->order, $actor);

        return $receipt;
    }
}

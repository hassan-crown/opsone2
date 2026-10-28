<?php

namespace App\Finance\Settlement;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Finance\MoneyLedger;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Money\Money;

/**
 * The refundable limit of one payment on one order (FIN-05, FIN-09, scenario 9): what the payment's money still sits
 * on the order (after executed refunds, transfers and reversals) minus every refund that holds a reservation —
 * requested, in progress or unresolved. An executed refund is already out of the ledger; a failed or cancelled one
 * reserves nothing. Read under the order lock so two refunds never both fit in the same limit.
 */
final class RefundLimits
{
    public static function placed(int $paymentId, int $orderId): Money
    {
        return Money::of(array_sum(MoneyLedger::placesOf($paymentId, $orderId)));
    }

    public static function reserved(int $paymentId, int $orderId, ?int $except = null): Money
    {
        return Money::of((int) Refund::query()->where('payment_id', $paymentId)->where('order_id', $orderId)
            ->whereIn('status', array_map(fn (RefundStatus $s) => $s->value, array_filter(RefundStatus::cases(), fn (RefundStatus $s) => $s->reserves())))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->sum('amount_minor'));
    }

    public static function executed(int $paymentId, int $orderId): Money
    {
        return Money::of((int) Refund::query()->where('payment_id', $paymentId)->where('order_id', $orderId)
            ->where('status', RefundStatus::Executed->value)->sum('amount_minor'));
    }

    /**
     * What a new (or a retried) refund may still take. Zero for a payment that is not confirmed money.
     */
    public static function refundable(Payment $payment, int $orderId, ?int $except = null): Money
    {
        if ($payment->status !== PaymentStatus::Confirmed) {
            return Money::zero();
        }
        $left = self::placed($payment->id, $orderId)->minus(self::reserved($payment->id, $orderId, $except));

        return $left->isNegative() ? Money::zero() : $left;
    }

    /**
     * The order's credit that no reservation holds: what may still move to another order (TransferCredit).
     */
    public static function freeCredit(int $orderId): Money
    {
        return Money::of(array_sum(self::freeCreditByPayment($orderId)));
    }

    /**
     * Free credit per payment, oldest first: a payment's credit minus what its own pending refunds still need from its
     * money on the order (a refund takes the payment's credit first, then its allocations), never below zero.
     *
     * @return array<int, int> payment id → halalas
     */
    public static function freeCreditByPayment(int $orderId): array
    {
        $free = [];
        foreach (MoneyLedger::creditByPayment($orderId) as $paymentId => $credit) {
            $spare = self::placed($paymentId, $orderId)->minor - self::reserved($paymentId, $orderId)->minor;
            $free[$paymentId] = max(0, min($credit, $spare));
        }

        return $free;
    }
}

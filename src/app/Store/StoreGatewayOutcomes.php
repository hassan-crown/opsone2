<?php

namespace App\Store;

use App\Actions\Store\ReadyOrderPayment;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Finance\Gateway\GatewayOutcomes;
use App\Finance\Gateway\VerifiedGatewayEvent;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;
use App\Models\Store\StoreCheckout;
use App\Settlement\SettlementFollowUps;

/**
 * What a verified payment result means for a store checkout (WEB-06, M-05-T03..T07, M-31-T03/T04/T06, M-32-T02/T03),
 * in the payment's transaction under the order's lock:
 *
 * - confirmed money with the reservation standing → order confirmed, coupon consumed, link used (sale pinned when
 *   paid inside its validity);
 * - confirmed money after the window ended → T06, then T07 re-reserves when every line is still available; after a
 *   cancellation the customer chose the order stays cancelled (scenario 5); either way without a sale the money is
 *   kept, a refund item opens on the order's settlement follow-up (made through P6 M-12) and a person is told;
 * - an unresolved or mismatching result → the order needs attention, the reservation is kept;
 * - a settled failure (failed, cancelled) → released like an expiry.
 *
 * A replayed notice never reaches here (GatewayPayments answers it as M-11-T08).
 */
final class StoreGatewayOutcomes implements GatewayOutcomes
{
    public function __construct(
        private readonly ReadyOrderPayment $orders,
        private readonly Coupons $coupons,
        private readonly StoreLinks $links,
        private readonly StoreNotices $notices,
        private readonly SettlementFollowUps $settlement,
    ) {}

    public function applied(Payment $payment, Order $order, PaymentStatus $from, VerifiedGatewayEvent $event, bool $late): void
    {
        $checkout = StoreCheckout::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
        if ($checkout === null) {
            return;
        }
        $context = ['checkout' => $checkout->number, 'transaction' => $event->transactionId];

        if ($late) {
            // Money reported after the payment was settled as failed/cancelled locally: never posted silently.
            $this->needsAttention($checkout, $order, 'late_payment_unposted');

            return;
        }

        match ($payment->status) {
            PaymentStatus::Confirmed => $this->paid($checkout, $order, $payment, $event, $context),
            PaymentStatus::Unresolved => $this->unresolved($checkout, $order, $event, $payment, $context),
            PaymentStatus::Failed, PaymentStatus::Cancelled => $this->failed($checkout, $order, $payment->status, $context),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function paid(StoreCheckout $checkout, Order $order, Payment $payment, VerifiedGatewayEvent $event, array $context): void
    {
        $paidAt = $event->occurredAt ?? now()->toImmutable();
        $checkout->paid_at = $paidAt;

        $confirmed = false;
        $reason = 'paid_order_closed';
        if ($order->status === ReadyOrderStatus::AwaitingPayment->value) {
            $this->orders->confirm($order, $context);
            $confirmed = true;
        } elseif ($order->status === ReadyOrderStatus::NeedsAttention->value && $this->orders->hasActiveReservations($order)) {
            $this->orders->resolveConfirmed($order, $context);
            $confirmed = true;
        } elseif ($order->status === ReadyOrderStatus::Cancelled->value && $checkout->status === CheckoutStatus::Abandoned) {
            // A cancellation the customer chose stays: the money is kept for settlement, no sale restarts (scenario 5).
            $reason = 'paid_after_cancellation';
        } elseif ($order->status === ReadyOrderStatus::Cancelled->value) {
            // The window ended without money: the late success re-reserves if every line is still there (M-05-T06/T07).
            $confirmed = $this->orders->latePayment($order, $context);
            $reason = 'late_payment_no_stock';
        }

        if (! $confirmed) {
            // Never oversold: the money waits for a refund made through P6 (M-12, P-43), followed per order.
            $this->settlement->add($order, SettlementItem::REFUND, ReadyOrderPayment::SOURCE, ['checkout' => $checkout->number, 'reason' => $reason],
                $payment->amount_minor, 'payment', $payment->id);
            $this->needsAttention($checkout, $order, $reason);

            return;
        }

        $this->coupons->consume($checkout);
        if ($checkout->link_id !== null) {
            $this->links->endAttempt($checkout, true, $paidAt, $order);
        }
        $from = $checkout->status;
        $checkout->forceFill(['status' => CheckoutStatus::Paid, 'attention_reason' => null, 'lock_version' => $checkout->lock_version + 1])->save();
        Audit::record(AuditAction::StoreCheckoutPaid, $order, null, before: ['status' => $from->value], after: [
            'checkout' => $checkout->number, 'total_minor' => $checkout->total_minor, 'paid_at' => $paidAt->toIso8601String(),
        ], source: ReadyOrderPayment::SOURCE, label: $order->number);
        $this->notices->paid($checkout, $order);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function unresolved(StoreCheckout $checkout, Order $order, VerifiedGatewayEvent $event, Payment $payment, array $context): void
    {
        $mismatch = $event->outcome === 'succeeded';
        if ($order->status === ReadyOrderStatus::AwaitingPayment->value) {
            $this->orders->flag($order, $mismatch ? 'amount_mismatch' : 'payment_unresolved', $context + [
                'expected_minor' => $payment->amount_minor, 'reported_minor' => $event->amountMinor, 'currency' => $event->currency,
            ]);
        }
        $this->needsAttention($checkout, $order, $mismatch ? 'amount_mismatch' : 'payment_unresolved');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failed(StoreCheckout $checkout, Order $order, PaymentStatus $status, array $context): void
    {
        if (! $checkout->status->isOpen()) {
            return; // already released by the expiry service; the payment is now settled too
        }
        $reason = $status === PaymentStatus::Failed ? 'payment_failed' : 'payment_cancelled';
        if ($order->status === ReadyOrderStatus::AwaitingPayment->value) {
            $this->orders->cancel($order, $reason, $context);
        }
        $this->coupons->release($checkout, $reason);
        if ($checkout->link_id !== null) {
            $this->links->endAttempt($checkout, false);
        }
        $checkout->forceFill(['status' => CheckoutStatus::Expired, 'lock_version' => $checkout->lock_version + 1])->save();
        Audit::record(AuditAction::StoreCheckoutReleased, $order, null, before: ['status' => CheckoutStatus::PendingPayment->value],
            after: ['checkout' => $checkout->number, 'status' => CheckoutStatus::Expired->value], reason: $reason, source: ReadyOrderPayment::SOURCE, label: $order->number);
    }

    public function needsAttention(StoreCheckout $checkout, Order $order, string $reason): void
    {
        $from = $checkout->status;
        $checkout->forceFill(['status' => CheckoutStatus::NeedsAttention, 'attention_reason' => $reason, 'lock_version' => $checkout->lock_version + 1])->save();
        Audit::record(AuditAction::StoreCheckoutNeedsAttention, $order, null, before: ['status' => $from->value],
            after: ['checkout' => $checkout->number, 'reason' => $reason], source: ReadyOrderPayment::SOURCE, label: $order->number);
        $this->notices->attention($checkout, $order, $reason);
    }
}

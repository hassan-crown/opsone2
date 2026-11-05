<?php

namespace App\EInvoicing;

use App\Access\TransitionRecorder;
use App\Finance\PaymentConfirmed;
use App\Finance\Settlement\RefundExecuted;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatic documents (ADR-0033), after the money change committed: the first confirmed payment of an order issues its
 * tax invoice; an executed refund on an invoiced order issues a credit note for the refunded amount (capped at what is
 * still credited). Both are idempotent (one invoice per order, one credit note per refund) and never break the money
 * flow: a blocked or failed issue is logged without personal data and picked up by `einvoicing:sweep` or the owner.
 */
final class EInvoiceListeners
{
    public function __construct(private readonly InvoiceIssuer $issuer) {}

    public function onPayment(PaymentConfirmed $event): void
    {
        if (! self::active()) {
            return;
        }
        $payment = Payment::query()->find($event->paymentId);
        foreach ($event->orderIds as $orderId) {
            $order = Order::query()->find($orderId);
            if ($order === null) {
                continue;
            }
            $this->attempt(fn () => $this->issuer->invoiceForOrder($order, $payment, null, TransitionRecorder::SOURCE_SYSTEM), 'invoice', $orderId);
        }
    }

    public function onRefund(RefundExecuted $event): void
    {
        if (! self::active()) {
            return;
        }
        $invoice = EInvoice::query()->where('order_invoice_key', $event->orderId)->first();
        $refund = Refund::query()->find($event->refundId);
        if ($invoice === null || $refund === null) {
            return;
        }
        $reason = __('einvoicing.note.refund_reason', ['number' => $event->number], 'ar').' — '.mb_substr((string) $refund->reason, 0, 500);
        $this->attempt(fn () => $this->issuer->creditNote($invoice, $event->amountMinor, $reason, $refund, null, TransitionRecorder::SOURCE_SYSTEM), 'credit_note', $event->orderId);
    }

    public static function active(): bool
    {
        $settings = ZatcaSettings::current();

        return $settings->exists && $settings->enabled;
    }

    private function attempt(\Closure $work, string $kind, int $orderId): void
    {
        try {
            $work();
        } catch (InvoiceBlocked $e) {
            Log::info('E-invoice not issued', ['kind' => $kind, 'order_id' => $orderId, 'reason' => $e->reason]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\EInvoicing;

use App\Access\TransitionRecorder;
use App\Enums\PaymentStatus;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\Finance\Payment;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Orders paid since e-invoicing was switched on that still have no tax invoice (ADR-0032): the payment listener was
 * blocked (buyer address missing, no priced content) or failed. The settings page lists them; `einvoicing:sweep`
 * (hourly) retries them.
 */
final class MissingInvoices
{
    public function __construct(private readonly InvoiceIssuer $issuer) {}

    /**
     * @return Collection<int, Order>
     */
    public function orders(int $limit = 50): Collection
    {
        $settings = ZatcaSettings::current();
        if (! $settings->exists || ! $settings->enabled || $settings->enabled_at === null) {
            return new Collection;
        }

        $paid = Payment::query()
            ->whereIn('status', [PaymentStatus::Confirmed->value, PaymentStatus::Corrected->value])
            ->where('confirmed_at', '>=', $settings->enabled_at)
            ->select('order_id');

        return Order::query()
            ->whereIn('id', $paid)
            ->whereNotIn('id', EInvoice::query()->whereNotNull('order_invoice_key')->select('order_invoice_key'))
            ->with('customer')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{issued: int, blocked: array<string, int>}
     */
    public function sweep(int $limit = 200): array
    {
        $issued = 0;
        $blocked = [];
        foreach ($this->orders($limit) as $order) {
            $payment = Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Confirmed->value)->orderBy('confirmed_at')->first();
            try {
                $this->issuer->invoiceForOrder($order, $payment, null, TransitionRecorder::SOURCE_SYSTEM);
                $issued++;
            } catch (InvoiceBlocked $e) {
                $blocked[$e->reason] = ($blocked[$e->reason] ?? 0) + 1;
            }
        }

        return ['issued' => $issued, 'blocked' => $blocked];
    }
}

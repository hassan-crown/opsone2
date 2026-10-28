<?php

namespace App\Actions\StateMachines\M40Document;

use App\Enums\ExternalDocumentEvent;
use App\Enums\ExternalDocumentKind;
use App\Finance\MoneyLedger;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * The order event an external document covers: a payment of this order, a refund of it, the delivery or the order.
 */
final class DocumentLinks
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', 'in:'.implode(',', array_column(ExternalDocumentKind::cases(), 'value'))],
            'event' => ['required', 'in:'.implode(',', array_column(ExternalDocumentEvent::cases(), 'value'))],
            'payment_id' => ['nullable', 'required_if:event,payment', 'integer'],
            'refund_id' => ['nullable', 'required_if:event,refund', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: ExternalDocumentKind, event: ExternalDocumentEvent, payment_id: int|null, refund_id: int|null, note: string|null}
     */
    public static function resolve(Order $order, array $data): array
    {
        $event = ExternalDocumentEvent::from((string) $data['event']);
        $paymentId = null;
        $refundId = null;
        if ($event === ExternalDocumentEvent::Payment) {
            $payment = Payment::query()->find((int) $data['payment_id']);
            if ($payment === null || ($payment->order_id !== $order->id && MoneyLedger::placesOf($payment->id, $order->id) === [])) {
                throw ValidationException::withMessages(['payment_id' => __('invoices.validation.event')]);
            }
            $paymentId = $payment->id;
        }
        if ($event === ExternalDocumentEvent::Refund) {
            $refundId = Refund::query()->where('order_id', $order->id)->whereKey((int) $data['refund_id'])->value('id')
                ?? throw ValidationException::withMessages(['refund_id' => __('invoices.validation.event')]);
        }

        return [
            'kind' => ExternalDocumentKind::from((string) $data['kind']), 'event' => $event,
            'payment_id' => $paymentId, 'refund_id' => $refundId === null ? null : (int) $refundId,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }
}

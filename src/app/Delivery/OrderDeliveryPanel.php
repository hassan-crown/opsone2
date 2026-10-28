<?php

namespace App\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Delivery\DeliveryNote;
use App\Models\Order;
use App\Models\User;

/**
 * The delivery card of the order page: what is still owed, the notes so far and whether the viewer may record a
 * delivery (P-52). Null when the viewer has no delivery access on the order and nothing was delivered.
 */
final class OrderDeliveryPanel
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forOrder(Order $order, User $actor): ?array
    {
        $record = DeliveryAccess::canRecord($actor, $order);
        if (! $record) {
            return null;
        }
        $notes = DeliveryNote::query()->where('order_id', $order->id)->whereIn('status', [DeliveryStatus::Draft->value, DeliveryStatus::Confirmed->value])->orderByDesc('id')->limit(20)->get();
        $lines = DeliveryProgress::lines($order);

        return [
            'can_record' => DeliveryProgress::accepts($order) && array_sum(array_column($lines, 'deliverable')) > 0,
            'complete' => DeliveryProgress::complete($order),
            'notes' => $notes->map(fn (DeliveryNote $n) => ['id' => $n->id, 'number' => $n->number, 'status' => $n->status->value, 'delivered_on' => $n->delivered_on?->toDateString()])->values()->all(),
        ];
    }
}

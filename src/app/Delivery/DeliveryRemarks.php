<?php

namespace App\Delivery;

use App\Models\Delivery\DeliveryRemark;
use App\Models\Order;

/**
 * Read side of the blocking delivery remarks of an order (M-14-T04, ADR-0032/0035): an order holds any number of them,
 * each resolved on its own with who, when and how. Shared by the order page and the JSON contract other clients use
 * (the installation field app reuses it; see docs/p7-inventory-api.md «Delivery remarks»).
 */
final class DeliveryRemarks
{
    /**
     * Open remarks first (oldest first), then resolved ones (latest first).
     *
     * @return list<array<string, mixed>>
     */
    public static function forOrder(Order $order): array
    {
        $remarks = DeliveryRemark::query()->where('order_id', $order->id)
            ->with(['note:id,number', 'creator:id,name', 'resolver:id,name', 'followUp:id,name'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [DeliveryRemark::OPEN])
            ->orderByRaw('CASE WHEN status = ? THEN id ELSE -id END', [DeliveryRemark::OPEN])
            ->get();

        return array_values($remarks->map(fn (DeliveryRemark $r) => self::row($r))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(DeliveryRemark $r): array
    {
        return [
            'id' => $r->id, 'delivery_note_id' => $r->delivery_note_id, 'note' => $r->note?->number,
            'description' => $r->description, 'status' => $r->status,
            'recorded_by' => $r->creator?->name, 'recorded_at' => $r->created_at?->toIso8601String(),
            'follow_up' => $r->followUp?->name,
            'resolution' => $r->resolution, 'resolved_by' => $r->resolver?->name, 'resolved_at' => $r->resolved_at?->toIso8601String(),
            'lock_version' => $r->lock_version,
        ];
    }
}

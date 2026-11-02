<?php

namespace App\Delivery;

use App\Enums\CustomOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\ReadyOrderStatus;
use App\Inventory\MovementKind;
use App\Models\Delivery\DeliveryCorrectionLine;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryNoteLine;
use App\Models\Inventory\StockMovement;
use App\Models\Order;

/**
 * Read side of delivery corrections (M-14-T03, ADR-0035): how much of each note line / order line was taken back,
 * which reservation issues a note line can still give back, and when a correction is accepted.
 */
final class DeliveryCorrections
{
    /** A ready order after its first delivery and a made-to-order order while work is open; never a closed order. */
    public static function orderAccepts(Order $order): bool
    {
        $state = $order->state();

        return $order->isReady()
            ? in_array($state, [ReadyOrderStatus::PartiallyDelivered, ReadyOrderStatus::Delivered], true)
            : in_array($state, [CustomOrderStatus::InProgress, CustomOrderStatus::ExecutionFinished], true);
    }

    /**
     * @return array<int, int> delivery note line id → quantity taken back
     */
    public static function correctedByNoteLine(int $noteId): array
    {
        return DeliveryCorrectionLine::query()
            ->join('delivery_corrections', 'delivery_corrections.id', '=', 'delivery_correction_lines.delivery_correction_id')
            ->where('delivery_corrections.delivery_note_id', $noteId)
            ->groupBy('delivery_correction_lines.delivery_note_line_id')
            ->selectRaw('delivery_correction_lines.delivery_note_line_id as line_id, SUM(delivery_correction_lines.quantity_milli) as total')
            ->pluck('total', 'line_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return array<int, int> order line id → quantity taken back over every confirmed note of the order
     */
    public static function correctedByOrderLine(int $orderId): array
    {
        return DeliveryCorrectionLine::query()
            ->join('delivery_corrections', 'delivery_corrections.id', '=', 'delivery_correction_lines.delivery_correction_id')
            ->where('delivery_corrections.order_id', $orderId)
            ->groupBy('delivery_correction_lines.order_line_id')
            ->selectRaw('delivery_correction_lines.order_line_id as line_id, SUM(delivery_correction_lines.quantity_milli) as total')
            ->pluck('total', 'line_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Per reservation, what this note line issued and was not yet given back, newest reservation first.
     *
     * @return array<int, int> reservation id → quantity
     */
    public static function issuedPieces(int $noteId, DeliveryNoteLine $line): array
    {
        $issued = StockMovement::query()
            ->where('kind', MovementKind::IssueReserved->value)->where('source_type', 'delivery_note')->where('source_id', $noteId)
            ->where('idempotency_key', 'like', 'delivery:'.$noteId.':'.$line->order_line_id.':%')
            ->groupBy('reservation_id')->selectRaw('reservation_id, SUM(quantity_milli) as total')
            ->pluck('total', 'reservation_id')->map(fn ($v) => (int) $v)->all();
        $back = DeliveryCorrectionLine::query()->where('delivery_note_line_id', $line->id)->whereNotNull('stock_reservation_id')
            ->groupBy('stock_reservation_id')->selectRaw('stock_reservation_id, SUM(quantity_milli) as total')
            ->pluck('total', 'stock_reservation_id')->map(fn ($v) => (int) $v)->all();

        $pieces = [];
        foreach ($issued as $reservationId => $total) {
            $left = $total - ($back[$reservationId] ?? 0);
            if ($left > 0) {
                $pieces[(int) $reservationId] = $left;
            }
        }
        krsort($pieces);

        return $pieces;
    }

    /** The order's «first delivery» date as the due rule reads it now (first confirmed note). */
    public static function firstDeliveryOn(int $orderId): ?string
    {
        $on = DeliveryNote::query()->where('order_id', $orderId)->where('status', DeliveryStatus::Confirmed->value)
            ->whereNotNull('delivered_on')->orderBy('delivered_on')->orderBy('id')->value('delivered_on');

        return $on === null ? null : substr((string) $on, 0, 10);
    }
}

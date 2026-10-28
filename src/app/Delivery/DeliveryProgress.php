<?php

namespace App\Delivery;

use App\Enums\CustomOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\ReadyOrderStatus;
use App\Inventory\Quantity;
use App\Models\Delivery\DeliveryNoteLine;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Collection;

/**
 * What an order still owes the customer (FUL-09: «لا تتجاوز الكمية الفعالة غير المسلمة»). Per active line: ordered,
 * delivered (confirmed notes only) and deliverable now. A ready line's deliverable quantity is what its open
 * reservations still hold — a delivery never exceeds what was reserved, and a cancelled remainder is already released;
 * a custom line's is ordered − delivered (no manufactured stock is created, FUL-10). Read it under the order lock
 * when it decides a write.
 */
final class DeliveryProgress
{
    /** Order states that accept a delivery: ready after confirmation, custom while work is open. */
    public static function accepts(Order $order): bool
    {
        $state = $order->state();

        return $order->isReady()
            ? in_array($state, [ReadyOrderStatus::Confirmed, ReadyOrderStatus::PartiallyDelivered], true)
            : in_array($state, [CustomOrderStatus::InProgress, CustomOrderStatus::ExecutionFinished], true);
    }

    /**
     * @return array<int, array{line: OrderLine, ordered: int, delivered: int, released: int, deliverable: int}> by order line id
     */
    public static function lines(Order $order): array
    {
        $delivered = self::deliveredByLine($order->id);
        $reservations = $order->isReady()
            ? StockReservation::query()->where('order_id', $order->id)->get()->groupBy('order_line_id')
            : new Collection;

        $rows = [];
        foreach ($order->activeLines()->get() as $line) {
            /** @var OrderLine $line */
            if ($order->isReady() ? $line->line_type->value !== 'ready_variant' : $line->line_type->value === 'ready_variant') {
                continue;
            }
            $ordered = Quantity::parse($line->quantity);
            $done = $delivered[$line->id] ?? 0;
            if ($order->isReady()) {
                /** @var Collection<int, StockReservation> $held */
                $held = $reservations->get($line->id) ?? new Collection;
                $released = (int) $held->sum('released_milli');
                $deliverable = (int) $held->sum(fn (StockReservation $r) => $r->status->isOpen() ? $r->remainingMilli() : 0);
            } else {
                $released = 0;
                $deliverable = max(0, $ordered - $done);
            }
            $rows[$line->id] = ['line' => $line, 'ordered' => $ordered, 'delivered' => $done, 'released' => $released, 'deliverable' => $deliverable];
        }

        return $rows;
    }

    /**
     * Delivery is complete when every effective quantity was handed over (or released by an approved cancellation) and
     * something was actually delivered.
     */
    public static function complete(Order $order): bool
    {
        $rows = self::lines($order);

        return $rows !== []
            && array_sum(array_column($rows, 'delivered')) > 0
            && array_sum(array_column($rows, 'deliverable')) === 0;
    }

    /**
     * Confirmed delivered quantity per order line (the base returns are checked against).
     *
     * @return array<int, int>
     */
    public static function deliveredByLine(int $orderId): array
    {
        return DeliveryNoteLine::query()
            ->join('delivery_notes', 'delivery_notes.id', '=', 'delivery_note_lines.delivery_note_id')
            ->where('delivery_notes.order_id', $orderId)->where('delivery_notes.status', DeliveryStatus::Confirmed->value)
            ->groupBy('delivery_note_lines.order_line_id')
            ->selectRaw('delivery_note_lines.order_line_id as line_id, SUM(delivery_note_lines.quantity_milli) as total')
            ->pluck('total', 'line_id')->map(fn ($v) => (int) $v)->all();
    }
}

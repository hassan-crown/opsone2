<?php

namespace App\Actions\Inventory;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M24Reservation\T01IssueReservation;
use App\Enums\ReadyOrderStatus;
use App\Inventory\OrderStock;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;

/**
 * Stock side of a ready delivery (P-52, M-24-T01) for the delivery session to call inside its own M-05-T08: under the
 * order lock, issue part of one of the order's reservations once per idempotency key. It never changes the order's
 * status — the delivery action decides partially delivered / delivered from the effective quantity.
 */
final class IssueReadyReservation
{
    public function __construct(private readonly T01IssueReservation $issue) {}

    public function handle(User $actor, Order $order, StockReservation $reservation, int $quantityMilli, string $sourceType, int $sourceId, string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): StockReservation
    {
        $order = OrderStock::lockOrder($order->id);
        if ($reservation->order_id !== $order->id) {
            abort(404);
        }
        Access::authorize($actor, Permission::P52, $order);
        if (! in_array($order->state(), [ReadyOrderStatus::Confirmed, ReadyOrderStatus::PartiallyDelivered], true)) {
            throw new InvalidTransition($order->kind->machine(), 'T08', 'not_from_'.$order->status);
        }

        return $this->issue->handle($reservation, $quantityMilli, $actor, [
            'source_type' => $sourceType, 'source_id' => $sourceId, 'idempotency_key' => $idempotencyKey,
        ], $source);
    }
}

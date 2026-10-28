<?php

namespace App\Cancellation;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Settlement\SettlementFollowUps;

/**
 * The cancellation entry point on the order page: open CancelReview (P-18 while the order can still be cancelled),
 * a pending request, and the settlement follow-up's state for anyone who sees the order.
 */
final class OrderCancellationPanel
{
    /**
     * @return array{can_review: bool, pending: array{id: int, number: string}|null, settlement: array{status: string, open: int}|null}|null
     */
    public static function forOrder(Order $order, User $actor): ?array
    {
        $review = Access::can($actor, Permission::P18, $order);
        if ($review) {
            try {
                CancellationImpact::ensureCancellable($order);
            } catch (InvalidTransition) {
                $review = false;
            }
        }
        $pending = OrderCancellation::query()->where('active_key', $order->id)->first();
        $followUp = SettlementFollowUps::forOrder($order);
        if (! $review && $pending === null && $followUp === null) {
            return null;
        }

        return [
            'can_review' => $review,
            'pending' => $pending === null ? null : ['id' => $pending->id, 'number' => $pending->number],
            'settlement' => $followUp === null ? null : [
                'status' => $followUp->status,
                'open' => SettlementItem::query()->where('settlement_followup_id', $followUp->id)->where('status', SettlementItem::OPEN)->count(),
            ],
        ];
    }
}

<?php

namespace App\Workflow;

use App\Models\Order;
use App\Models\OrderAction;

/**
 * Locks the order, then re-reads the action (M-27 Actions).
 */
final class ActionLoader
{
    /**
     * @return array{0: Order, 1: OrderAction}
     */
    public static function lock(OrderAction $action, ?int $orderVersion): array
    {
        $order = WorkflowLock::order($action->order_id, $orderVersion);
        $fresh = OrderAction::query()->findOrFail($action->id);
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }
}

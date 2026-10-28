<?php

namespace App\Orders;

use App\Models\Order;
use App\Support\StaleRecord;
use LogicException;

/**
 * Optimistic locking for orders: re-read the row FOR UPDATE inside the write transaction and refuse the write when
 * the client's `lock_version` is not the current one. Every successful write bumps the version.
 */
final class OrderLock
{
    public static function fresh(Order $order, int $expectedVersion): Order
    {
        $locked = Order::query()->lockForUpdate()->find($order->id);
        if ($locked === null) {
            throw new LogicException('The order disappeared.');
        }
        if ($locked->lock_version !== $expectedVersion) {
            throw new StaleRecord($locked->lock_version);
        }

        return $locked;
    }
}

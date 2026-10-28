<?php

namespace App\Workflow;

use App\Enums\OrderKind;
use App\Models\Order;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every engine write on an order's work (stages, actions, tasks) first locks the order row — the first read of its
 * transaction (App\Support\LockingTransaction, MariaDB 1020) — so concurrent claims, completions and source signals
 * on one order queue instead of interleaving (one main stage, no double task, no double effect).
 */
final class WorkflowLock
{
    public static function order(int $orderId, ?int $expectedVersion = null): Order
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Workflow writes run inside a transaction.');
        }

        $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new LogicException('The order disappeared.');
        if ($order->kind !== OrderKind::Custom) {
            throw new LogicException('Only made-to-order orders run a workflow.');
        }
        if ($expectedVersion !== null && $order->lock_version !== $expectedVersion) {
            throw new StaleRecord($order->lock_version);
        }

        return $order;
    }

    /**
     * Records that the order's work changed, so a screen holding an older `lock_version` must reload.
     */
    public static function touch(Order $order): void
    {
        Order::query()->whereKey($order->id)->increment('lock_version');
        $order->lock_version++;
    }
}

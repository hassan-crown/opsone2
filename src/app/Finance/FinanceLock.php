<?php

namespace App\Finance;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\OrderKind;
use App\Enums\StateMachine;
use App\Models\Finance\OrderFinance;
use App\Models\Order;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every money write locks its orders first — the same row the workflow engine and the agreement lock, so a payment,
 * a stage completion and an agreement approval on one order queue — then the order's finance head, whose
 * `lock_version` is the version the screen read. Several orders are locked in id order (no deadlock between a
 * split payment and a transfer). Money is accepted on a closed order too: a real payment that arrives after a
 * cancellation is recorded for settlement and never restarts work (SRS-INT-08).
 */
final class FinanceLock
{
    /**
     * @param  list<int>  $orderIds
     * @return array<int, Order> keyed by id
     */
    public static function orders(array $orderIds, StateMachine $machine, string $code): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Money writes run inside a transaction.');
        }

        $ids = array_values(array_unique($orderIds));
        sort($ids);
        $orders = [];
        foreach ($ids as $id) {
            $order = Order::query()->lockForUpdate()->find($id) ?? throw new LogicException('The order disappeared.');
            if ($order->kind !== OrderKind::Custom) {
                throw new InvalidTransition($machine, $code, 'ready_order');
            }
            $orders[$id] = $order;
        }

        return $orders;
    }

    public static function order(int $orderId, StateMachine $machine, string $code): Order
    {
        return self::orders([$orderId], $machine, $code)[$orderId];
    }

    /**
     * The order's finance head, created on first use (under the order lock, so never twice) and locked.
     */
    public static function head(Order $order): OrderFinance
    {
        $head = OrderFinance::query()->where('order_id', $order->id)->lockForUpdate()->first();
        if ($head === null) {
            $head = OrderFinance::query()->create(['order_id' => $order->id]);
        }
        $head->setRelation('order', $order);

        return $head;
    }

    /**
     * `null` skips the check (system callers only).
     */
    public static function checkVersion(OrderFinance $head, ?int $expected): void
    {
        if ($expected !== null && $head->lock_version !== $expected) {
            throw new StaleRecord($head->lock_version);
        }
    }

    /**
     * Any change of the order's money makes a screen that read the older version reload.
     */
    public static function touch(OrderFinance $head): void
    {
        OrderFinance::query()->whereKey($head->id)->increment('lock_version');
        $head->lock_version++;
    }

    /**
     * The version a screen reads before writing (1 before the head exists).
     */
    public static function versionOf(Order $order): int
    {
        return (int) (OrderFinance::query()->where('order_id', $order->id)->value('lock_version') ?? 1);
    }
}

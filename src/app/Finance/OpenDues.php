<?php

namespace App\Finance;

use App\Enums\DueStatus;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\OrderDue;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dues with something left to collect across orders, with their derived date and status — the source of the finance
 * work list, the collection report and the reminders. Bounded (ADR-0014): at most `LIMIT` orders.
 */
final class OpenDues
{
    public const LIMIT = 500;

    /**
     * @param  Builder<Order>  $orders  already narrowed to what the reader may see
     * @return list<array{due: OrderDue, order: Order, due_on: string|null, status: DueStatus, remaining: int}>
     */
    public static function of(Builder $orders): array
    {
        $orderIds = (clone $orders)->whereIn('orders.id', OrderDue::query()->whereNull('removed_at')->select('order_id'))
            ->orderBy('orders.id')->limit(self::LIMIT)->pluck('orders.id')->all();
        if ($orderIds === []) {
            return [];
        }

        $models = Order::query()->with(['customer', 'branch'])->whereIn('id', $orderIds)->get()->keyBy('id');
        $dues = OrderDue::query()->whereIn('order_id', $orderIds)->whereNull('removed_at')->orderBy('position')->get()->groupBy('order_id');
        $allocated = MoneyEntry::query()->whereIn('order_id', $orderIds)->whereNotNull('order_due_id')->groupBy('order_due_id')
            ->selectRaw('order_due_id, SUM(amount_minor) as allocated')->pluck('allocated', 'order_due_id')->all();

        $rows = [];
        foreach ($dues as $orderId => $orderDues) {
            $order = $models->get($orderId);
            if ($order === null) {
                continue;
            }
            $dates = DueCalendar::dates($order, $orderDues);
            foreach ($orderDues as $due) {
                $held = (int) ($allocated[$due->id] ?? 0);
                $remaining = $due->amount_minor - $held;
                if ($remaining <= 0) {
                    continue;
                }
                $rows[] = [
                    'due' => $due, 'order' => $order, 'due_on' => $dates[$due->id],
                    'status' => DueCalendar::status($due->amount_minor, $held, $dates[$due->id]), 'remaining' => $remaining,
                ];
            }
        }

        return $rows;
    }
}

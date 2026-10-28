<?php

namespace App\Finance\Settlement;

use App\Enums\DisputeStatus;
use App\Enums\RefundStatus;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\Refund;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * FinHome items of 6c (FIN-11: «الردود المعلقة»): refunds not yet executed and open disputes on the orders the viewer
 * may see with P-37, oldest first.
 */
final class SettlementWorkItems
{
    public const KINDS = ['refund', 'dispute'];

    /**
     * @param  Builder<Order>  $orders  scoped by P-37
     * @return list<array<string, mixed>>
     */
    public static function for(Builder $orders): array
    {
        $items = [];
        $refunds = Refund::query()->with('order.customer')
            ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::InProgress->value, RefundStatus::Unresolved->value, RefundStatus::Failed->value])
            ->whereIn('order_id', (clone $orders)->select('orders.id'))->orderBy('id')->limit(200)->get();
        foreach ($refunds as $refund) {
            $items[] = [
                'kind' => 'refund',
                'key' => 'refund-'.$refund->id,
                'refund_id' => $refund->id,
                'order' => ['id' => $refund->order->id, 'number' => $refund->order->number, 'customer' => $refund->order->customer->name],
                'label' => __('refunds.status.'.$refund->status->value),
                'date' => $refund->created_at->toDateString(),
                'age_days' => (int) $refund->created_at->diffInDays(now()),
                'amount_minor' => $refund->amount_minor,
            ];
        }

        $disputes = FinancialDispute::query()->with('order.customer')->where('status', '!=', DisputeStatus::Resolved->value)
            ->whereIn('order_id', (clone $orders)->select('orders.id'))->orderBy('id')->limit(200)->get();
        foreach ($disputes as $dispute) {
            $items[] = [
                'kind' => 'dispute',
                'key' => 'dispute-'.$dispute->id,
                'dispute_id' => $dispute->id,
                'order' => ['id' => $dispute->order->id, 'number' => $dispute->order->number, 'customer' => $dispute->order->customer->name],
                'label' => __('disputes.status.'.$dispute->status->value),
                'date' => $dispute->created_at->toDateString(),
                'age_days' => (int) $dispute->created_at->diffInDays(now()),
                'amount_minor' => $dispute->amount_minor,
            ];
        }

        return $items;
    }
}

<?php

namespace App\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Delivery\DeliveryRemarks;
use App\Enums\DeliveryStatus;
use App\Finance\DebtWriteOffs;
use App\Models\Delivery\DeliveryNote;
use App\Models\Order;
use App\Models\User;

/**
 * The closure card of the order page (M-04-T07 / M-05-T10): what still blocks the closure, the open delivery remarks
 * (M-14-T04, each resolved on its own), the debt write-off (P-103, ADR-0034) and who may close, write off or record a remark. Null when the order cannot close or hold remarks from here.
 */
final class OrderClosurePanel
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forOrder(Order $order, User $actor): ?array
    {
        $notes = DeliveryNote::query()->where('order_id', $order->id)->where('status', DeliveryStatus::Confirmed->value)->orderBy('id')->get(['id', 'number']);
        $canRemark = $notes->isNotEmpty() && Access::can($actor, Permission::P52, $order);
        $closable = OrderClosure::closable($order);
        if (! $closable && ! $canRemark && ! DebtWriteOffs::any($order->id)) {
            return null;
        }

        return [
            'closable' => $closable,
            'can_close' => $closable && Access::can($actor, OrderClosure::permissionFor($order), $order),
            'blockers' => $closable ? array_map(fn (array $b) => [
                'key' => $b['key'], 'text' => __('order.closure.blocker.'.$b['key'], $b['details']), 'items' => $b['items'] ?? [],
            ], OrderClosure::blockers($order)) : [],
            'can_remark' => $canRemark,
            'notes' => $notes->map(fn (DeliveryNote $n) => ['id' => $n->id, 'number' => $n->number])->values()->all(),
            'remarks' => DeliveryRemarks::forOrder($order),
            'write_off' => DebtWriteOffs::panel($order, $actor),
        ];
    }
}

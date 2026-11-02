<?php

namespace App\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\DeliveryStatus;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryRemark;
use App\Models\Order;
use App\Models\User;

/**
 * The closure card of the order page (M-04-T07 / M-05-T10): what still blocks the closure, the open delivery remarks
 * (M-14-T04) and who may close or record a remark. Null when the order cannot close or hold remarks from here.
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
        if (! $closable && ! $canRemark) {
            return null;
        }

        return [
            'closable' => $closable,
            'can_close' => $closable && Access::can($actor, OrderClosure::permissionFor($order), $order),
            'blockers' => $closable ? array_map(fn (array $b) => ['key' => $b['key'], 'text' => __('order.closure.blocker.'.$b['key'], $b['details'])], OrderClosure::blockers($order)) : [],
            'can_remark' => $canRemark,
            'notes' => $notes->map(fn (DeliveryNote $n) => ['id' => $n->id, 'number' => $n->number])->values()->all(),
            'remarks' => DeliveryRemark::query()->where('order_id', $order->id)->with('note:id,number')->orderByDesc('id')->limit(20)->get()
                ->map(fn (DeliveryRemark $r) => ['id' => $r->id, 'note' => $r->note?->number, 'description' => $r->description, 'status' => $r->status, 'resolution' => $r->resolution, 'lock_version' => $r->lock_version])->values()->all(),
        ];
    }
}

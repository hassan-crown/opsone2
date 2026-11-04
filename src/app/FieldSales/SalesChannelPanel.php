<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\SalesChannel;
use App\Models\Order;
use App\Models\User;

/**
 * The order page's «قناة البيع» card (ADR-0041): where the order was sold, its field seller and, with P-106, the reps
 * who could be named as seller for its branch.
 */
final class SalesChannelPanel
{
    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, User $viewer): array
    {
        $order->loadMissing('seller:id,name');
        $canChange = Access::can($viewer, Permission::P106, $order);

        return [
            'channel' => $order->sales_channel->value,
            'seller' => $order->seller?->name,
            'seller_id' => $order->seller_id,
            'lock_version' => $order->lock_version,
            'channels' => SalesChannel::values(),
            'can_change' => $canChange,
            'reps' => $canChange ? array_map(fn (User $u) => ['id' => $u->id, 'name' => $u->name], FieldReps::forBranch($order->branch_id)) : [],
        ];
    }
}

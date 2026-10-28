<?php

namespace App\Delivery;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Delivery\DeliveryNote;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and records deliveries (P-52, scoped on the order). The order's amounts need P-37 on the same order and
 * are a separate protection from recording a delivery.
 */
final class DeliveryAccess
{
    public static function canRecord(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P52, $order);
    }

    public static function canView(User $user, DeliveryNote $note): bool
    {
        return Access::can($user, Permission::P52, $note);
    }

    public static function canSeeAmounts(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P37, $order);
    }

    public static function any(User $user): bool
    {
        return Access::can($user, Permission::P52);
    }

    /**
     * Orders in the user's delivery scope.
     *
     * @return Builder<Order>
     */
    public static function orders(User $user): Builder
    {
        return ScopedQuery::apply(Order::query(), $user, [Permission::P52]);
    }

    /**
     * @param  Builder<DeliveryNote>  $query
     * @return Builder<DeliveryNote>
     */
    public static function scoped(Builder $query, User $user): Builder
    {
        return $query->whereIn('delivery_notes.order_id', self::orders($user)->select('orders.id'));
    }
}

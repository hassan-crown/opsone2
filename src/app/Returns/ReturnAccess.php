<?php

namespace App\Returns;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Inventory\StockLocation;
use App\Models\Order;
use App\Models\Returns\OrderReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who acts on a ready-goods return (P04): P-42 or P-53 raise the request, P-102 decides (and withdraws), P-53 receives
 * and inspects (on the order and on the receiving location), P-49 joins P-53 for a correction with stock effect,
 * P-42 asks the refund and P-37 sees amounts. Each action checks its own grant; one person may hold several.
 */
final class ReturnAccess
{
    public static function canRequest(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P53, $order) || Access::can($user, Permission::P42, $order);
    }

    public static function canDecide(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P102, $order);
    }

    public static function canReceive(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P53, $order);
    }

    public static function canReceiveAt(User $user, StockLocation $location): bool
    {
        return Access::can($user, Permission::P53, $location);
    }

    public static function canRefund(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P42, $order);
    }

    public static function canSeeAmounts(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P37, $order);
    }

    public static function canView(User $user, OrderReturn $return): bool
    {
        foreach ([Permission::P53, Permission::P102, Permission::P42] as $permission) {
            if (Access::can($user, $permission, $return)) {
                return true;
            }
        }

        return false;
    }

    public static function any(User $user): bool
    {
        return Access::can($user, Permission::P53) || Access::can($user, Permission::P102) || Access::can($user, Permission::P42);
    }

    /**
     * Ready orders in the user's return scope.
     *
     * @return Builder<Order>
     */
    public static function orders(User $user): Builder
    {
        return ScopedQuery::apply(Order::query(), $user, [Permission::P53, Permission::P102, Permission::P42]);
    }

    /**
     * Locations where the user may receive returned goods.
     *
     * @return Builder<StockLocation>
     */
    public static function locations(User $user): Builder
    {
        return ScopedQuery::apply(StockLocation::query(), $user, [Permission::P53], 'branch_id', null, null)->where('status', 'active');
    }

    /**
     * @param  Builder<OrderReturn>  $query
     * @return Builder<OrderReturn>
     */
    public static function scoped(Builder $query, User $user): Builder
    {
        return $query->whereIn('order_returns.order_id', self::orders($user)->select('orders.id'));
    }
}

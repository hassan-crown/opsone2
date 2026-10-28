<?php

namespace App\Commissions;

use App\Access\Access;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Models\Branch;
use App\Models\Commissions\OrderSale;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows sales to what the viewer's grants cover, mirroring `OrderSale` as a ScopedRecord: `own` is the sale's
 * member (SAL-15: a sales person sees only their own commissions), the other scopes follow the order. Lists never
 * show what opening the sale would refuse.
 */
final class SaleScope
{
    /**
     * @param  Builder<OrderSale>  $query
     * @return Builder<OrderSale>
     */
    public static function apply(Builder $query, User $user, Permission $permission = Permission::P94): Builder
    {
        $grants = Access::grantsFor($user, $permission);
        foreach ($grants as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return $query;
            }
        }
        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($grants, $user) {
            foreach ($grants as $grant) {
                match ($grant->scope) {
                    Scope::Own => $q->orWhere('order_sales.user_id', $user->id),
                    Scope::Assigned => $q->orWhereIn('order_sales.order_id', Order::query()->where('assignee_id', $user->id)->select('id')),
                    Scope::Branches => $q->orWhereIn('order_sales.order_id', Order::query()->whereIn('branch_id', $grant->targets)->select('id')),
                    Scope::Region => $q->orWhereIn('order_sales.order_id', Order::query()->whereIn(
                        'branch_id', Branch::query()->whereIn('region_id', $grant->targets)->select('id'),
                    )->select('id')),
                    default => null,
                };
            }
            $q->orWhereRaw('1 = 0');
        });
    }
}

<?php

namespace App\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Procurement\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees purchase orders: whoever may create/cancel them (P-46) or receive them (P-47) within their scope. Costs
 * are a separate permission (P-82) on the same order.
 */
final class PurchaseAccess
{
    public static function canView(User $user, PurchaseOrder $po): bool
    {
        return Access::can($user, Permission::P46, $po) || Access::can($user, Permission::P47, $po);
    }

    public static function canSeeCosts(User $user, PurchaseOrder $po): bool
    {
        return Access::can($user, Permission::P82, $po);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public static function scoped(Builder $query, User $user): Builder
    {
        return ScopedQuery::apply($query, $user, [Permission::P46, Permission::P47], 'branch_id', 'created_by', 'responsible_id');
    }

    /** Suppliers are facility-wide: anyone who manages them or buys from them may list them. */
    public static function canListSuppliers(User $user): bool
    {
        return Access::can($user, Permission::P45) || Access::can($user, Permission::P46) || Access::can($user, Permission::P47);
    }
}

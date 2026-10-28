<?php

namespace App\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Enums\MembershipStatus;
use App\Enums\OrderKind;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and changes orders. Viewing needs P-16 over the record; the money on it (line prices, totals) needs P-37
 * over the record — a separate protection from viewing the order (matrix: «المالية لها قيود مستقلة»).
 */
final class OrderAccess
{
    public static function canView(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P16, $order);
    }

    public static function canSeeFinancials(User $user, Order $order): bool
    {
        return Access::can($user, Permission::P37, $order);
    }

    /**
     * @return Builder<Order>
     */
    public static function visible(User $user): Builder
    {
        return ScopedQuery::apply(Order::query(), $user, [Permission::P16]);
    }

    /**
     * A valid responsible person: an active staff member who could open the order (P-16 over it).
     */
    public static function isValidAssignee(?int $userId, Order $order): bool
    {
        if ($userId === null) {
            return false;
        }

        $user = User::query()->find($userId);
        $active = Membership::query()->where('user_id', $userId)->where('status', MembershipStatus::Active)->exists();

        return $user !== null && $active && ! $user->is_support && Access::can($user, Permission::P16, $order);
    }

    /**
     * Active branches where the user may open an order of this kind (P-15, plus `ready_goods` for ready orders).
     *
     * @return list<Branch>
     */
    public static function creatableBranches(User $user, OrderKind $kind): array
    {
        return array_values(Branch::query()->where('status', 'active')->orderBy('id')->get()
            ->filter(fn (Branch $b) => Access::can($user, Permission::P15, new Order(['kind' => $kind, 'branch_id' => $b->id])))
            ->all());
    }
}

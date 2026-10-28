<?php

namespace App\Orders;

use App\Customers\CustomerAccess;
use App\Enums\MembershipStatus;
use App\Inventory\OrderStock;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Organization\OrgPresenter;

/**
 * Impact review of a branch transfer (COM-12, Q-29 option a): what the move touches, computed from server facts
 * and shown before the single atomic apply. Appointments (P5) are still reported as `available: false`; reserved
 * stock comes from the inventory domain (P7a): reservations stay at their locations and do not move with the order.
 */
final class BranchTransferImpact
{
    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, Branch $target): array
    {
        $moved = $order->inBranch($target->id);

        return [
            'from_branch' => self::branch($order->branch),
            'to_branch' => self::branch($target),
            'assignee' => self::assignee($order->assignee, fn (User $u) => OrderAccess::isValidAssignee($u->id, $moved)),
            'open_lines' => $order->activeLines()->count(),
            'appointments' => ['available' => false, 'upcoming' => null],
            'reserved_stock' => OrderStock::transferImpact($order, $target->id),
        ];
    }

    /**
     * Moving a customer changes who sees and serves the customer; its orders keep their own branches.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Customer $customer, Branch $target): array
    {
        $moved = self::customerIn($customer, $target->id);

        return [
            'from_branch' => self::branch($customer->branch),
            'to_branch' => self::branch($target),
            'assignee' => self::assignee($customer->assignee, fn (User $u) => self::isValidCustomerAssignee($u->id, $moved), required: false),
            'orders_staying' => Order::query()->where('customer_id', $customer->id)->count(),
            'open_interests' => Interest::query()->where('customer_id', $customer->id)->whereIn('status', ['new', 'following'])->count(),
            'appointments' => ['available' => false, 'upcoming' => null],
        ];
    }

    public static function customerIn(Customer $customer, int $branchId): Customer
    {
        $copy = $customer->replicate();
        $copy->branch_id = $branchId;
        $copy->exists = true;

        return $copy;
    }

    /**
     * The customer as the target branch would hold it, with no owner or assignee: the P-10 check on the target must
     * come from a branch, region or facility grant, so an own/assigned grant cannot move a customer into any branch.
     */
    public static function customerTarget(Customer $customer, int $branchId): Customer
    {
        $copy = self::customerIn($customer, $branchId);
        $copy->created_by = null;
        $copy->assignee_id = null;

        return $copy;
    }

    public static function isValidCustomerAssignee(?int $userId, Customer $customer): bool
    {
        $user = $userId === null ? null : User::query()->find($userId);
        if ($user === null || $user->is_support) {
            return false;
        }

        return Membership::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active)->exists()
            && CustomerAccess::canViewCustomer($user, $customer);
    }

    /**
     * @param  callable(User): bool  $keeps
     * @return array{current: array{id: int, name: string}|null, keeps_access: bool, requires_new: bool}
     */
    private static function assignee(?User $current, callable $keeps, bool $required = true): array
    {
        $ok = $current !== null && $keeps($current);

        return [
            'current' => $current === null ? null : ['id' => $current->id, 'name' => $current->name],
            'keeps_access' => $ok,
            // An order always has a responsible person; a customer may become unassigned.
            'requires_new' => $required && ! $ok,
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private static function branch(Branch $branch): array
    {
        return ['id' => $branch->id, 'name' => OrgPresenter::name($branch)];
    }
}

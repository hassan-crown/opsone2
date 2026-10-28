<?php

namespace App\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and changes customers and interests. Viewing a customer needs P-09 or P-10 over the record;
 * viewing an interest needs P-09 or P-15. Sensitive customer fields need P-10 over the record.
 */
final class CustomerAccess
{
    public static function canViewCustomer(User $user, Customer $customer): bool
    {
        return Access::can($user, Permission::P10, $customer) || Access::can($user, Permission::P09, $customer);
    }

    public static function canSeeSensitive(User $user, Customer $customer): bool
    {
        return Access::can($user, Permission::P10, $customer);
    }

    public static function canViewInterest(User $user, Interest $interest): bool
    {
        return Access::can($user, Permission::P09, $interest) || Access::can($user, Permission::P15, $interest);
    }

    /**
     * @return Builder<Customer>
     */
    public static function visibleCustomers(User $user): Builder
    {
        return ScopedQuery::apply(Customer::query(), $user, [Permission::P09, Permission::P10]);
    }

    /**
     * @return Builder<Interest>
     */
    public static function visibleInterests(User $user): Builder
    {
        return ScopedQuery::apply(Interest::query(), $user, [Permission::P09, Permission::P15]);
    }

    /**
     * Active branches in which the user may create customers/interests (P-09).
     *
     * @return list<Branch>
     */
    public static function creatableBranches(User $user): array
    {
        return array_values(Branch::query()->where('status', 'active')->orderBy('id')->get()
            ->filter(fn (Branch $b) => Access::can($user, Permission::P09, new Customer(['branch_id' => $b->id])))
            ->all());
    }
}

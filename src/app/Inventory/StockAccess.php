<?php

namespace App\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and does what with stock (P-45..P-53 matrix, ADR-0017). A location is a ScopedRecord on its branch; a
 * facility-wide location is covered by facility grants only. P-48 alone shows quantities (on hand, reserved,
 * available); the movement history needs P-48 plus a stock-operating grant (P-46/P-47/P-49/P-50) on the location —
 * the matrix's «منحة ر3 ور4 تتيح المتاح فقط دون الحركات».
 */
final class StockAccess
{
    /** Grants that, with P-48, open the movement history. */
    public const HISTORY_GRANTS = [Permission::P46, Permission::P47, Permission::P49, Permission::P50];

    /**
     * Locations a permission covers for the user.
     *
     * @return Builder<StockLocation>
     */
    public static function locations(User $user, Permission ...$permissions): Builder
    {
        return ScopedQuery::apply(StockLocation::query(), $user, array_values($permissions), 'branch_id', null, null);
    }

    public static function canView(User $user, StockLocation $location): bool
    {
        return Access::can($user, Permission::P48, $location);
    }

    public static function canSeeHistory(User $user, StockLocation $location): bool
    {
        if (! Access::can($user, Permission::P48, $location)) {
            return false;
        }
        foreach (self::HISTORY_GRANTS as $permission) {
            if (Access::can($user, $permission, $location)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Builder<StockLocation>
     */
    public static function historyLocations(User $user): Builder
    {
        return self::locations($user, Permission::P48)->whereIn('id', self::locations($user, ...self::HISTORY_GRANTS)->select('id'));
    }

    public static function any(User $user): bool
    {
        return Access::can($user, Permission::P48) || Access::can($user, Permission::P49) || Access::can($user, Permission::P50);
    }
}

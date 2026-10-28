<?php

namespace App\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Inventory\StockAccess;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Stock locations offered when choosing where a purchase lands: the active locations the signed-in user may receive
 * into (P-47, scoped by branch). The stock domain owns locations; procurement references them by id only.
 */
final class Locations
{
    /**
     * The location a purchase names or receives into, checked on the server (the options list is only the screen's
     * help): active, facility-wide or in the purchase's branch, and within the actor's grant of `$permission`.
     */
    public static function check(User $actor, ?int $locationId, int $branchId, Permission $permission, string $field = 'location_id'): ?StockLocation
    {
        if ($locationId === null) {
            return null;
        }
        $location = StockLocation::query()->find($locationId);
        if ($location === null || ! $location->isActive() || ($location->branch_id !== null && $location->branch_id !== $branchId)
            || ! Access::can($actor, $permission, $location)) {
            throw ValidationException::withMessages([$field => __('procurement.error.location')]);
        }

        return $location;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function options(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        return array_values(StockAccess::locations($user, Permission::P47)
            ->where('status', 'active')
            ->orderBy('code')
            ->get()
            ->map(fn (StockLocation $l): array => ['id' => $l->id, 'name' => $l->code.' — '.($l->name->get() ?? $l->code)])
            ->all());
    }
}

<?php

namespace App\Procurement;

use App\Access\Permission;
use App\Inventory\StockAccess;
use App\Models\Inventory\StockLocation;
use Illuminate\Support\Facades\Auth;

/**
 * Stock locations offered when choosing where a purchase lands: the active locations the signed-in user may receive
 * into (P-47, scoped by branch). The stock domain owns locations; procurement references them by id only.
 */
final class Locations
{
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

<?php

namespace App\Actions\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\StockUnit;
use App\Models\Branch;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Inventory\InventorySettings;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock setup: locations (P-49 on the location's branch, a facility-wide one needs a facility grant), a tracked
 * item's unit and reorder level (P-49), and the facility's enabled units and alerts (P-02).
 */
final class ManageStockSetup
{
    /**
     * @param  array{code: string, name: array{ar: string|null, en: string|null}, branch_id: int|null, status?: string, manager_id?: int|null}  $data
     */
    public function saveLocation(User $actor, ?StockLocation $location, array $data, ?int $lockVersion = null): StockLocation
    {
        return DB::transaction(function () use ($actor, $location, $data, $lockVersion) {
            $target = new StockLocation(['branch_id' => $data['branch_id']]);
            Access::authorize($actor, Permission::P49, $target);
            if ($data['branch_id'] !== null && ! Branch::query()->whereKey($data['branch_id'])->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['branch_id' => __('stock.validation.branch_inactive')]);
            }
            if ($location !== null) {
                $location = StockLocation::query()->lockForUpdate()->findOrFail($location->id);
                Access::authorize($actor, Permission::P49, $location);
                if ($lockVersion !== null && $location->lock_version !== $lockVersion) {
                    throw new StaleRecord($location->lock_version);
                }
                if ($location->is_default && ($data['status'] ?? 'active') !== 'active') {
                    throw ValidationException::withMessages(['status' => __('stock.validation.default_location')]);
                }
                if (($data['status'] ?? 'active') !== 'active' && DB::table('stock_balances')->where('location_id', $location->id)
                    ->where(fn ($q) => $q->where('on_hand_milli', '>', 0)->orWhere('reserved_milli', '>', 0))->exists()) {
                    throw ValidationException::withMessages(['status' => __('stock.validation.location_not_empty')]);
                }
            }
            $before = $location === null ? [] : $this->snapshot($location);
            $location ??= new StockLocation;
            $location->fill([
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => $data['name'],
                'branch_id' => $data['branch_id'],
                'status' => $data['status'] ?? 'active',
                'manager_id' => $data['manager_id'] ?? null,
            ]);
            if ($location->exists) {
                $location->lock_version++;
            }
            $location->save();
            Audit::record(AuditAction::StockLocationSaved, $location, $actor, before: $before, after: $this->snapshot($location), label: $location->code);

            return $location;
        });
    }

    public function updateItem(User $actor, string $itemType, int $itemId, ?string $unit, ?int $reorderLevelMilli): StockItem
    {
        return DB::transaction(function () use ($actor, $itemType, $itemId, $unit, $reorderLevelMilli) {
            Access::authorize($actor, Permission::P49);
            $settings = InventorySettings::current();
            $item = StockItems::find($itemType, $itemId);
            if ($item === null && $itemType === StockItem::MATERIAL && $unit !== null) {
                // First stock setup of a material whose label does not name a fixed unit.
                $chosen = StockUnit::tryFrom($unit);
                if ($chosen === null || ! $settings->enables($chosen)) {
                    throw ValidationException::withMessages(['unit' => __('stock.validation.unit_disabled')]);
                }
                $material = CatalogMaterial::query()->find($itemId);
                if ($material === null || ! $material->track_quantity) {
                    throw ValidationException::withMessages(['item_id' => __('stock.validation.not_tracked')]);
                }
                $item = StockItem::query()->create(['item_type' => $itemType, 'item_id' => $itemId, 'unit' => $chosen]);
            }
            $item ??= StockItems::resolve($itemType, $itemId);
            $item = StockItem::query()->lockForUpdate()->findOrFail($item->id);
            $before = ['unit' => $item->unit->value, 'reorder_level' => $item->reorder_level_milli === null ? null : Quantity::format($item->reorder_level_milli)];

            if ($unit !== null && $unit !== $item->unit->value) {
                // Q-43 (a): no conversion — the unit can change only before the item has any movement.
                $chosen = StockUnit::tryFrom($unit);
                if ($chosen === null || ! $settings->enables($chosen) || $itemType === StockItem::READY_VARIANT
                    || StockMovement::query()->where('stock_item_id', $item->id)->exists()) {
                    throw ValidationException::withMessages(['unit' => __('stock.validation.unit_locked')]);
                }
                $item->unit = $chosen;
            }
            $item->reorder_level_milli = $reorderLevelMilli;
            $item->lock_version++;
            $item->save();
            Audit::record(AuditAction::StockItemUpdated, $item, $actor, before: $before, after: [
                'unit' => $item->unit->value, 'reorder_level' => $reorderLevelMilli === null ? null : Quantity::format($reorderLevelMilli),
            ], label: StockItems::describe($item)['name']);

            return $item;
        });
    }

    /**
     * @param  list<string>  $units
     */
    public function updateSettings(User $actor, array $units, bool $lowStockAlerts): InventorySettings
    {
        return DB::transaction(function () use ($actor, $units, $lowStockAlerts) {
            Access::authorize($actor, Permission::P02);
            $settings = InventorySettings::query()->lockForUpdate()->findOrFail(InventorySettings::current()->id);
            $units = array_values(array_unique([StockUnit::Piece->value, ...array_filter($units, fn (string $u) => StockUnit::tryFrom($u) !== null)]));
            // A unit in use stays enabled: disabling it would strand existing balances.
            $inUse = StockItem::query()->distinct()->pluck('unit')->map(fn ($u) => $u instanceof StockUnit ? $u->value : (string) $u)->all();
            $missing = array_diff($inUse, $units);
            if ($missing !== []) {
                throw ValidationException::withMessages(['units' => __('stock.validation.unit_in_use')]);
            }
            $before = ['units' => $settings->enabled_units, 'low_stock_alerts' => $settings->low_stock_alerts];
            $settings->enabled_units = $units;
            $settings->low_stock_alerts = $lowStockAlerts;
            $settings->lock_version++;
            $settings->save();
            Audit::record(AuditAction::InventorySettingsUpdated, $settings, $actor, before: $before, after: ['units' => $units, 'low_stock_alerts' => $lowStockAlerts]);

            return $settings;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(StockLocation $location): array
    {
        return [
            'code' => $location->code, 'name' => $location->name->toArray(), 'branch_id' => $location->branch_id,
            'status' => $location->status, 'manager_id' => $location->manager_id,
        ];
    }
}

<?php

namespace App\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inventory\InventorySettings;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockCountLine;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferLine;
use App\Models\User;
use App\Organization\OrgPresenter;
use Illuminate\Support\Facades\DB;

/**
 * Read models of the stock screens (Stock*, Ready* stock side). Every list is narrowed to the locations the viewer's
 * grants cover (StockAccess); quantities leave as decimal text in the item's unit, never floats.
 */
final class StockPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function location(StockLocation $location, ?User $viewer = null): array
    {
        return [
            'id' => $location->id,
            'code' => $location->code,
            'name' => (string) $location->name->get(app()->getLocale()),
            'name_i18n' => $location->name->toArray(),
            'branch' => $location->branch === null ? null : ['id' => $location->branch->id, 'name' => OrgPresenter::name($location->branch)],
            'status' => $location->status,
            'is_default' => $location->is_default,
            'manager' => $location->manager === null ? null : ['id' => $location->manager->id, 'name' => $location->manager->name],
            'lock_version' => $location->lock_version,
        ] + ($viewer === null ? [] : ['can' => [
            'edit' => Access::can($viewer, Permission::P49, $location),
            'history' => StockAccess::canSeeHistory($viewer, $location),
            'adjust' => Access::can($viewer, Permission::P49, $location),
            'transfer' => Access::can($viewer, Permission::P50, $location),
        ]]);
    }

    /**
     * Balances (FUL-01): on hand, reserved, available and in transit towards the location, per item and location.
     *
     * @return array<string, mixed>
     */
    public static function balances(User $viewer, ?int $locationId, string $search, bool $lowOnly): array
    {
        $locations = StockAccess::locations($viewer, Permission::P48)->with(['branch', 'manager'])->orderByDesc('is_default')->orderBy('code')->get();
        $ids = array_values(array_map(intval(...), $locations->pluck('id')->all()));
        if ($locationId !== null) {
            $ids = in_array($locationId, $ids, true) ? [$locationId] : [];
        }
        $balances = StockBalance::query()->with('item')->whereIn('location_id', $ids)
            ->where(fn ($q) => $q->where('on_hand_milli', '>', 0)->orWhere('reserved_milli', '>', 0)->orWhereIn('stock_item_id', StockItem::query()->whereNotNull('reorder_level_milli')->select('id')))
            ->orderBy('location_id')->orderBy('stock_item_id')->limit(1000)->get();
        $incoming = self::incoming($ids);
        $totals = [];
        foreach (StockBalance::query()->whereIn('stock_item_id', $balances->pluck('stock_item_id')->unique())->get() as $b) {
            $totals[$b->stock_item_id] = ($totals[$b->stock_item_id] ?? 0) + $b->availableMilli();
        }
        $byLocation = $locations->keyBy('id');
        $rows = [];
        foreach ($balances as $b) {
            $item = StockItems::describe($b->item);
            if ($search !== '' && mb_stripos($item['name'].' '.($item['code'] ?? ''), $search) === false) {
                continue;
            }
            $low = $b->item->reorder_level_milli !== null && ($totals[$b->stock_item_id] ?? 0) < $b->item->reorder_level_milli;
            if ($lowOnly && ! $low) {
                continue;
            }
            $location = $byLocation->get($b->location_id);
            $rows[] = [
                'id' => $b->id,
                'location' => ['id' => $b->location_id, 'code' => $location?->code, 'name' => $location === null ? '' : (string) $location->name->get(app()->getLocale())],
                'item' => $item,
                'on_hand' => Quantity::format($b->on_hand_milli),
                'reserved' => Quantity::format($b->reserved_milli),
                'available' => Quantity::format($b->availableMilli()),
                'incoming' => Quantity::format($incoming[$b->location_id.':'.$b->stock_item_id] ?? 0),
                'low' => $low,
            ];
        }

        return [
            'balances' => $rows,
            'locations' => $locations->map(fn (StockLocation $l) => self::location($l, $viewer))->values()->all(),
            'filters' => ['location_id' => $locationId, 'q' => $search, 'low' => $lowOnly],
            'units' => StockUnit::values(),
            'enabled_units' => InventorySettings::current()->enabled_units,
            'can' => [
                'adjust' => Access::can($viewer, Permission::P49),
                'transfer' => Access::can($viewer, Permission::P50),
                'history' => StockAccess::historyLocations($viewer)->exists(),
                'settings' => Access::can($viewer, Permission::P02),
            ],
            'settings' => ['units' => InventorySettings::current()->enabled_units, 'low_stock_alerts' => InventorySettings::current()->low_stock_alerts],
        ];
    }

    /**
     * Quantity in transit towards each (location, item): exited at the source, not yet arrived.
     *
     * @param  list<int>  $locationIds
     * @return array<string, int>
     */
    private static function incoming(array $locationIds): array
    {
        $rows = DB::table('stock_transfer_lines')->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
            ->where('stock_transfers.status', TransferStatus::InTransit->value)->whereIn('stock_transfers.to_location_id', $locationIds)
            ->groupBy('stock_transfers.to_location_id', 'stock_transfer_lines.stock_item_id')
            ->selectRaw('stock_transfers.to_location_id as location_id, stock_transfer_lines.stock_item_id as item_id, SUM(stock_transfer_lines.quantity_milli) as qty')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->location_id.':'.$row->item_id] = (int) $row->qty;
        }

        return $out;
    }

    /**
     * Tracked items for pickers: every ready variant and every quantity-tracked material, with its unit.
     *
     * @return list<array{type: string, item_id: int, name: string, code: string|null, unit: string|null}>
     */
    public static function trackable(string $search = ''): array
    {
        $locale = app()->getLocale();
        $out = [];
        foreach (CatalogReadyVariant::query()->with('product')->whereNull('disabled_at')->orderBy('id')->limit(300)->get() as $v) {
            $out[] = ['type' => StockItem::READY_VARIANT, 'item_id' => $v->id, 'name' => $v->product->name->get($locale).' — '.$v->name->get($locale), 'code' => $v->code, 'unit' => StockUnit::Piece->value];
        }
        foreach (CatalogMaterial::query()->where('track_quantity', true)->whereNull('disabled_at')->orderBy('id')->limit(300)->get() as $m) {
            $out[] = ['type' => StockItem::MATERIAL, 'item_id' => $m->id, 'name' => (string) $m->name->get($locale), 'code' => null, 'unit' => StockItems::unitFor(StockItem::MATERIAL, $m->id)];
        }
        if ($search !== '') {
            $out = array_values(array_filter($out, fn (array $i) => mb_stripos($i['name'].' '.($i['code'] ?? ''), $search) !== false));
        }

        return $out;
    }

    /**
     * @param  array{location_id?: int|null, kind?: string|null, item_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public static function movements(User $viewer, array $filters): array
    {
        $locations = StockAccess::historyLocations($viewer)->orderBy('code')->get();
        $query = StockMovement::query()->with(['item', 'location', 'actor:id,name'])->whereIn('location_id', $locations->pluck('id'))
            ->latest('id')->limit(300);
        if (($filters['location_id'] ?? null) !== null) {
            $query->where('location_id', $filters['location_id']);
        }
        if (($filters['kind'] ?? null) !== null) {
            $query->where('kind', $filters['kind']);
        }
        if (($filters['item_id'] ?? null) !== null) {
            $query->where('stock_item_id', $filters['item_id']);
        }

        return [
            'movements' => $query->get()->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'kind' => $m->kind->value,
                'location' => ['id' => $m->location_id, 'code' => $m->location->code],
                'item' => StockItems::describe($m->item),
                'quantity' => Quantity::format($m->quantity_milli),
                'on_hand_delta' => Quantity::format($m->on_hand_delta),
                'reserved_delta' => Quantity::format($m->reserved_delta),
                'on_hand_after' => Quantity::format($m->on_hand_after),
                'reserved_after' => Quantity::format($m->reserved_after),
                'document_number' => $m->document_number,
                'order_id' => $m->order_id,
                'source' => $m->source_type === null ? null : ['type' => $m->source_type, 'id' => $m->source_id],
                'reason' => $m->reason,
                'actor' => $m->actor?->name,
                'occurred_at' => $m->occurred_at->toIso8601String(),
            ])->all(),
            'locations' => $locations->map(fn (StockLocation $l) => ['id' => $l->id, 'code' => $l->code, 'name' => (string) $l->name->get(app()->getLocale())])->all(),
            'kinds' => array_map(fn (MovementKind $k) => $k->value, MovementKind::cases()),
            'filters' => ['location_id' => $filters['location_id'] ?? null, 'kind' => $filters['kind'] ?? null, 'item_id' => $filters['item_id'] ?? null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transfer(StockTransfer $t, User $viewer): array
    {
        return [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status->value,
            'from' => ['id' => $t->from->id, 'code' => $t->from->code, 'name' => (string) $t->from->name->get(app()->getLocale())],
            'to' => ['id' => $t->to->id, 'code' => $t->to->code, 'name' => (string) $t->to->name->get(app()->getLocale())],
            'note' => $t->note,
            'lines' => $t->lines()->with('item')->get()->map(fn (StockTransferLine $l) => ['id' => $l->id, 'item' => StockItems::describe($l->item), 'quantity' => Quantity::format($l->quantity_milli)])->all(),
            'created_at' => $t->created_at->toIso8601String(),
            'dispatched_at' => $t->dispatched_at?->toIso8601String(),
            'received_at' => $t->received_at?->toIso8601String(),
            'lock_version' => $t->lock_version,
            'can_act' => Access::can($viewer, Permission::P50, $t),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transfers(User $viewer): array
    {
        $visible = StockAccess::locations($viewer, Permission::P48, Permission::P50)->pluck('id');
        $transfers = StockTransfer::query()->with(['from', 'to'])
            ->where(fn ($q) => $q->whereIn('from_location_id', $visible)->orWhereIn('to_location_id', $visible))
            ->latest('id')->limit(200)->get();

        return [
            'transfers' => $transfers->map(fn (StockTransfer $t) => self::transfer($t, $viewer))->all(),
            'locations' => StockAccess::locations($viewer, Permission::P50)->where('status', 'active')->orderBy('code')->get()
                ->map(fn (StockLocation $l) => ['id' => $l->id, 'code' => $l->code, 'name' => (string) $l->name->get(app()->getLocale())])->all(),
            'items' => self::trackable(),
            'can' => ['create' => Access::can($viewer, Permission::P50)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function count(StockCount $count, User $viewer, bool $withLines = true): array
    {
        $balances = $withLines ? StockBalance::query()->where('location_id', $count->location_id)->get()->keyBy('stock_item_id') : collect();

        return [
            'id' => $count->id,
            'number' => $count->number,
            'status' => $count->status->value,
            'location' => ['id' => $count->location->id, 'code' => $count->location->code, 'name' => (string) $count->location->name->get(app()->getLocale())],
            'reason' => $count->reason,
            'decision_note' => $count->decision_note,
            'created_by' => $count->creator?->name,
            'decided_by' => $count->decider?->name,
            'created_at' => $count->created_at->toIso8601String(),
            'decided_at' => $count->decided_at?->toIso8601String(),
            'lock_version' => $count->lock_version,
            'lines' => ! $withLines ? [] : $count->lines()->with('item')->get()->map(function (StockCountLine $l) use ($balances) {
                $current = $balances->has($l->stock_item_id) ? (int) $balances->get($l->stock_item_id)->on_hand_milli : 0;

                return [
                    'id' => $l->id,
                    'item' => StockItems::describe($l->item),
                    'expected' => Quantity::format($l->expected_milli),
                    'counted' => Quantity::format($l->counted_milli),
                    'current' => Quantity::format($current),
                    'difference' => Quantity::format($l->counted_milli - $current),
                ];
            })->all(),
            'can' => [
                'edit' => $count->status === CountStatus::Draft && StockAccess::canSeeHistory($viewer, $count->location),
                'decide' => $count->status === CountStatus::Submitted && Access::can($viewer, Permission::P49, $count->location),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function counts(User $viewer): array
    {
        $ids = StockAccess::historyLocations($viewer)->pluck('id')->merge(StockAccess::locations($viewer, Permission::P49)->pluck('id'))->unique();
        $counts = StockCount::query()->with(['location', 'creator:id,name', 'decider:id,name'])->whereIn('location_id', $ids)->latest('id')->limit(200)->get();

        return [
            'counts' => $counts->map(fn (StockCount $c) => self::count($c, $viewer, false))->all(),
            'locations' => StockAccess::historyLocations($viewer)->where('status', 'active')->orderBy('code')->get()
                ->map(fn (StockLocation $l) => ['id' => $l->id, 'code' => $l->code, 'name' => (string) $l->name->get(app()->getLocale())])->all(),
            'items' => self::trackable(),
        ];
    }

    /**
     * ReadyStock: open reservations of ready orders in the viewer's locations, with each order's number.
     *
     * @return array<string, mixed>
     */
    public static function readyReservations(User $viewer): array
    {
        $ids = StockAccess::locations($viewer, Permission::P48)->pluck('id');
        $rows = StockReservation::query()->with(['location', 'item', 'order:id,number,status,branch_id'])
            ->where('purpose', StockReservation::READY_ORDER)->whereIn('location_id', $ids)
            ->whereIn('status', [ReservationStatus::Active->value, ReservationStatus::PartiallyIssued->value])
            ->latest('id')->limit(300)->get();

        return [
            'reservations' => $rows->map(fn (StockReservation $r) => OrderStock::reservation($r) + [
                'order' => $r->order === null ? null : ['id' => $r->order->id, 'number' => $r->order->number, 'status' => $r->order->status],
            ])->all(),
        ];
    }
}

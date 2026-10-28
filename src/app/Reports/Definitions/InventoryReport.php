<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Inventory\StockAccess;
use App\Inventory\StockItems;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Inventory valuation and movements (SHR-12). Quantities need the movement-history grant on the location (P-48 plus
 * a stock-operating grant), and value needs P-82: cost is the weighted average NET cost of accepted purchase lines,
 * so VAT never enters the valuation and an item never purchased is «unvalued», not zero.
 */
final class InventoryReport implements Report
{
    public function key(): string
    {
        return 'inventory';
    }

    public function requires(): Permission
    {
        return Permission::P48;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('inventory', $s);
        $locations = $s->branchFilter(StockAccess::locations($s->viewer, Permission::P60)
            ->whereIn('stock_locations.id', StockAccess::historyLocations($s->viewer)->select('stock_locations.id')), 'stock_locations.branch_id');
        $locationIds = $locations->pluck('stock_locations.id')->all();
        $names = StockLocation::query()->whereIn('id', $locationIds)->get()->keyBy('id');

        $balances = StockBalance::query()->with('item')->whereIn('location_id', $locationIds)->orderBy('location_id')->orderBy('stock_item_id')->get();
        $costs = $s->costsVisible() ? $this->averageCosts() : [];

        $availableByItem = [];
        $value = 0;
        $unvalued = [];
        $rows = [];
        foreach ($balances as $b) {
            $available = max(0, $b->on_hand_milli - $b->reserved_milli);
            $availableByItem[$b->stock_item_id] = ($availableByItem[$b->stock_item_id] ?? 0) + $available;
            $cost = $costs[$b->item->item_type.':'.$b->item->item_id] ?? null;
            $line = null;
            if ($s->costsVisible()) {
                if ($b->on_hand_milli > 0 && $cost === null) {
                    $unvalued[$b->stock_item_id] = true;
                }
                if ($cost !== null) {
                    $line = Support::divRound($b->on_hand_milli * $cost['cost'], $cost['accepted']);
                    $value += $line;
                }
            }
            $location = $names->get($b->location_id);
            $rows[] = [
                'location' => $location === null ? '' : $location->code, 'item' => StockItems::describe($b->item)['name'], 'unit' => $b->item->unit->value,
                'on_hand_milli' => $b->on_hand_milli, 'reserved_milli' => $b->reserved_milli, 'available_milli' => $available,
                'value_minor' => $line,
            ];
        }
        $below = 0;
        foreach ($balances->unique('stock_item_id') as $b) {
            if ($b->item->reorder_level_milli !== null && ($availableByItem[$b->stock_item_id] ?? 0) < $b->item->reorder_level_milli) {
                $below++;
            }
        }

        $movements = StockMovement::query()->whereIn('location_id', $locationIds)
            ->whereBetween('occurred_at', [Support::at($s->start()), Support::at($s->end())]);
        $received = (int) (clone $movements)->where('on_hand_delta', '>', 0)->sum('on_hand_delta');
        $issued = -(int) (clone $movements)->where('on_hand_delta', '<', 0)->sum('on_hand_delta');

        $r->metric('inventory.items_in_stock', $balances->where('on_hand_milli', '>', 0)->count())
            ->metric('inventory.below_reorder', $below)
            ->metric('inventory.valuation_minor', $value)
            ->metric('inventory.unvalued_items', count($unvalued))
            ->metric('inventory.received_milli', $received)
            ->metric('inventory.issued_milli', $issued);

        $byKind = (clone $movements)->groupBy('kind')->orderBy('kind')->selectRaw('kind as k, SUM(on_hand_delta) as s')->pluck('s', 'k')->map(fn ($v) => (int) $v)->all();
        $r->chart('movements_by_kind', 'bar', 'quantity', Support::enumPoints($byKind));

        return $r->table([
            ['key' => 'location', 'type' => 'text'], ['key' => 'item', 'type' => 'text'], ['key' => 'unit', 'type' => 'enum'],
            ['key' => 'on_hand_milli', 'type' => 'qty'], ['key' => 'reserved_milli', 'type' => 'qty'], ['key' => 'available_milli', 'type' => 'qty'],
            ['key' => 'value_minor', 'type' => 'money', 'cost' => true],
        ], $rows);
    }

    /**
     * item_type:item_id → accepted milli and the net cost of those accepted quantities (halalas).
     *
     * @return array<string, array{accepted: int, cost: int}>
     */
    private function averageCosts(): array
    {
        $out = [];
        $lines = PurchaseOrderLine::query()->where('accepted_milli', '>', 0)->where('quantity_milli', '>', 0)->whereNotNull('unit_cost_minor')
            ->whereIn('purchase_order_id', PurchaseOrder::query()->whereNotIn('status', ['draft'])->select('id'))->get();
        foreach ($lines as $l) {
            $k = $l->item_type.':'.$l->item_id;
            $out[$k] ??= ['accepted' => 0, 'cost' => 0];
            $out[$k]['accepted'] += $l->accepted_milli;
            $out[$k]['cost'] += Support::divRound($l->net_minor * $l->accepted_milli, $l->quantity_milli);
        }

        return $out;
    }
}

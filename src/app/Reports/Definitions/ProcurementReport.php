<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;

/**
 * Procurement (SHR-12): remaining = effective ordered − accepted (a rejected quantity never satisfies the need).
 * Purchase cost needs P-82 even in the export. A purchase REFERENCE (customer basket grouping) is never a value:
 * sales numbers are per order only, so a reference and its orders are never summed twice.
 */
final class ProcurementReport implements Report
{
    public const OPEN = ['ordered', 'partially_received'];

    public function key(): string
    {
        return 'procurement';
    }

    public function requires(): ?Permission
    {
        return null;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('procurement', $s);
        $pos = $s->branchFilter(ScopedQuery::apply(PurchaseOrder::query(), $s->viewer, [Permission::P60], 'branch_id', null, null), 'purchase_orders.branch_id');
        $placed = (clone $pos)->whereBetween('ordered_at', [Support::at($s->start()), Support::at($s->end())])->where('status', '!=', 'draft');
        // Cost is its own grant (P-82): sums and cells only over purchase orders its scope covers.
        $costScope = ScopedQuery::apply(PurchaseOrder::query(), $s->viewer, [Permission::P82], 'branch_id', null, null)->select('purchase_orders.id');
        $placedCost = (clone $placed)->whereIn('purchase_orders.id', $costScope);
        $open = (clone $pos)->whereIn('status', self::OPEN);

        $remaining = 0;
        foreach (PurchaseOrderLine::query()->whereIn('purchase_order_id', (clone $open)->select('purchase_orders.id'))->get() as $l) {
            $remaining += max($l->quantity_milli - $l->accepted_milli, 0);
        }

        $r->metric('procurement.orders_placed', (clone $placed)->count())
            ->metric('procurement.ordered_net_minor', (int) (clone $placedCost)->sum('net_minor'))
            ->metric('procurement.ordered_gross_minor', (int) (clone $placedCost)->sum('gross_minor'))
            ->metric('procurement.remaining_milli', $remaining)
            ->metric('procurement.late_orders', (clone $open)->whereNotNull('expected_on')->where('expected_on', '<', AppointmentBooking::today())->count());

        $byStatus = (clone $placed)->groupBy('status')->orderBy('status')->selectRaw('status as s, COUNT(*) as c')->pluck('c', 's')->map(fn ($c) => (int) $c)->all();
        $r->chart('by_status', 'bar', 'count', Support::enumPoints($byStatus));

        $list = (clone $placed)->with('supplier')->orderBy('purchase_orders.id')->limit(ReportResult::ROW_CAP + 1)->get();
        $left = [];
        foreach (PurchaseOrderLine::query()->whereIn('purchase_order_id', $list->pluck('id'))->get() as $l) {
            $left[$l->purchase_order_id] = ($left[$l->purchase_order_id] ?? 0) + max($l->quantity_milli - $l->accepted_milli, 0);
        }
        $costIds = array_flip((clone $costScope)->whereIn('purchase_orders.id', $list->pluck('id'))->pluck('purchase_orders.id')->all());
        $rows = $list->map(fn (PurchaseOrder $p) => [
            'number' => $p->number, 'supplier' => (string) $p->supplier->name->get(app()->getLocale()), 'branch' => Support::branchName($p->branch_id),
            'status' => Support::v($p->status), 'ordered_on' => Support::date($p->ordered_at), 'expected_on' => $p->expected_on?->toDateString(),
            'remaining_milli' => in_array(Support::v($p->status), self::OPEN, true) ? ($left[$p->id] ?? 0) : 0,
            'net_minor' => isset($costIds[$p->id]) ? $p->net_minor : null, 'gross_minor' => isset($costIds[$p->id]) ? $p->gross_minor : null,
        ])->all();

        return $r->table([
            ['key' => 'number', 'type' => 'text'], ['key' => 'supplier', 'type' => 'text'], ['key' => 'branch', 'type' => 'text'], ['key' => 'status', 'type' => 'enum'],
            ['key' => 'ordered_on', 'type' => 'date'], ['key' => 'expected_on', 'type' => 'date'], ['key' => 'remaining_milli', 'type' => 'qty'],
            ['key' => 'net_minor', 'type' => 'money', 'cost' => true], ['key' => 'gross_minor', 'type' => 'money', 'cost' => true],
        ], $rows);
    }
}

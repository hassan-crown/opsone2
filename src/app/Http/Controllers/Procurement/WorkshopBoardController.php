<?php

namespace App\Http\Controllers\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Supplier;
use App\Organization\OrgPresenter;
use App\Procurement\PurchaseAccess;
use App\Procurement\PurchasePresenter;
use App\Procurement\WorkshopOrders;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Orders at workshops» (ADR-0038): the open purchase orders placed with workshops — or followed by an order's flow —
 * with their expected date and days late, filtered by workshop and branch. Scoped like the purchase list (P-46/P-47
 * over the order's branch); costs never appear here.
 */
final class WorkshopBoardController extends ProcurementController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P46) || Access::can($actor, Permission::P47), 403);

        $supplier = $request->filled('supplier') ? (int) $request->query('supplier') : null;
        $branch = $request->filled('branch') ? (int) $request->query('branch') : null;
        $lateOnly = $request->boolean('late');
        $today = now()->startOfDay();

        $rows = PurchaseAccess::scoped(WorkshopOrders::openQuery(), $actor)
            ->with(['supplier', 'branch', 'lines'])
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->when($lateOnly, fn ($q) => $q->whereNotNull('expected_on')->where('expected_on', '<', $today->toDateString()))
            ->orderByRaw('expected_on is null')->orderBy('expected_on')->orderBy('id')
            ->limit(200)->get();

        $orders = Order::query()->whereIn('id', $rows->flatMap(fn (PurchaseOrder $po) => $po->lines->pluck('order_id'))->filter()->unique()->all())
            ->get(['id', 'number'])->keyBy('id');

        $items = $rows->map(function (PurchaseOrder $po) use ($orders, $today) {
            $ordered = $po->lines->sum('quantity_milli');

            return [
                'id' => $po->id, 'number' => $po->number, 'status' => $po->status->value,
                'supplier' => ['id' => $po->supplier_id, 'name' => PurchasePresenter::text($po->supplier->name), 'is_workshop' => $po->supplier->is_workshop],
                'branch' => ['id' => $po->branch_id, 'name' => OrgPresenter::name($po->branch)],
                'orders' => $po->lines->pluck('order_id')->filter()->unique()->values()
                    ->map(fn ($id) => ['id' => (int) $id, 'number' => $orders->get($id)?->number])->all(),
                'expected_on' => $po->expected_on?->toDateString(),
                'days_late' => WorkshopOrders::lateDays($po, $today),
                'progress' => $ordered > 0 ? (int) floor($po->lines->sum('accepted_milli') * 100 / $ordered) : 0,
                'flow_reference' => $po->flow_reference,
            ];
        })->values();

        $workshops = Supplier::query()->where('is_workshop', true)->orderBy('id')->get()
            ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => PurchasePresenter::text($s->name)])->values();

        return Inertia::render('purchases/workshops', [
            'rows' => $items->all(),
            'summary' => [
                'open' => $items->count(),
                'late' => $items->where('days_late', '>', 0)->count(),
                'by_workshop' => $items->groupBy('supplier.id')->map(fn ($group) => [
                    'supplier' => $group->first()['supplier'],
                    'open' => $group->count(),
                    'late' => $group->where('days_late', '>', 0)->count(),
                ])->values()->all(),
            ],
            'filters' => ['supplier' => $supplier, 'branch' => $branch, 'late' => $lateOnly],
            'workshops' => $workshops->all(),
            'branches' => Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->values()->all(),
        ]);
    }
}

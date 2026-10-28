<?php

namespace App\Http\Controllers\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Inventory\OrderMaterials;
use App\Actions\StateMachines\M05ReadyOrder\T01ConfirmReadyOrder;
use App\Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder;
use App\Enums\OrderKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\MaterialMovementRequest;
use App\Http\Requests\Inventory\StockDecisionRequest;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\StockPresenter;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Orders\OrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Stock on an order: ready confirmation with reservation (M-05-T01, P-15), cancellation of the undelivered part
 * (M-05-T09, P-18), and the custom order's material reserve / issue / release screen (P-51, «حجز/صرف مواد المخصص»).
 */
class OrderStockController extends Controller
{
    use StockScreens;

    public function confirm(StockDecisionRequest $request, Order $order, T01ConfirmReadyOrder $confirm): JsonResponse
    {
        abort_unless($order->isReady(), 404);
        $order = $confirm->handle($this->actor($request), $order, $request->filled('location_id') ? $request->integer('location_id') : null, $request->version());

        return response()->json(['order' => ['id' => $order->id, 'status' => $order->status, 'lock_version' => $order->lock_version], 'stock' => OrderStock::readyLines($order)]);
    }

    public function cancelRemainder(StockDecisionRequest $request, Order $order, T09CancelReadyRemainder $cancel): JsonResponse
    {
        abort_unless($order->isReady(), 404);
        $order = $cancel->handle($this->actor($request), $order, (string) $request->validated('reason'), $request->version());

        return response()->json(['order' => ['id' => $order->id, 'status' => $order->status, 'lock_version' => $order->lock_version], 'stock' => OrderStock::readyLines($order)]);
    }

    public function materials(Request $request, Order $order): Response|JsonResponse
    {
        $actor = $this->actor($request);
        $manage = Access::can($actor, Permission::P51, $order);
        abort_unless($order->kind === OrderKind::Custom && ($manage || OrderAccess::canView($actor, $order)), 404);
        abort_unless($manage || Access::can($actor, Permission::P48), 403);
        $locations = StockLocation::query()->where('status', 'active')->with('branch')->orderByDesc('is_default')->orderBy('code')->get()
            ->filter(fn (StockLocation $l) => Access::can($actor, Permission::P51, $l))->values();
        $materials = CatalogMaterial::query()->where('track_quantity', true)->whereNull('disabled_at')->orderBy('id')->get();
        $availability = [];
        foreach ($materials as $m) {
            $item = StockItems::find(StockItem::MATERIAL, $m->id);
            foreach ($locations as $l) {
                $b = $item === null ? null : StockBalance::query()->where('location_id', $l->id)->where('stock_item_id', $item->id)->first();
                $availability[$m->id][$l->id] = Quantity::format($b === null ? 0 : max(0, $b->availableMilli()));
            }
        }

        return $this->screen($request, 'orders/materials', [
            'order' => ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'lock_version' => $order->lock_version],
            'reservations' => OrderStock::reservations($order)->map(fn (StockReservation $r) => OrderStock::reservation($r))->values()->all(),
            'issues' => StockMovement::query()->with('item')->where('order_id', $order->id)->where('kind', 'issue')->latest('id')->get()
                ->map(fn (StockMovement $m) => ['id' => $m->id, 'document_number' => $m->document_number, 'item' => StockItems::describe($m->item),
                    'quantity' => Quantity::format($m->quantity_milli), 'location_id' => $m->location_id, 'occurred_at' => $m->occurred_at->toIso8601String()])->all(),
            'locations' => $locations->map(fn (StockLocation $l) => StockPresenter::location($l))->all(),
            'materials' => $materials->map(fn (CatalogMaterial $m) => [
                'id' => $m->id, 'name' => (string) $m->name->get(app()->getLocale()), 'unit' => StockItems::unitFor(StockItem::MATERIAL, $m->id),
                'available' => $availability[$m->id] ?? [],
            ])->all(),
            'can' => ['manage' => $manage],
        ]);
    }

    public function reserve(MaterialMovementRequest $request, Order $order, OrderMaterials $materials): JsonResponse
    {
        $reservation = $materials->reserve($this->actor($request), $order, $request->integer('location_id'), $request->integer('material_id'),
            $request->quantityMilli(), $request->filled('lock_version') ? $request->integer('lock_version') : null);

        return response()->json(['reservation' => OrderStock::reservation($reservation->load(['location', 'item']))], 201);
    }

    public function issue(MaterialMovementRequest $request, Order $order, OrderMaterials $materials): JsonResponse
    {
        $movement = $materials->issue($this->actor($request), $order, $request->integer('location_id'), $request->integer('material_id'),
            $request->quantityMilli(), $request->filled('reservation_id') ? $request->integer('reservation_id') : null,
            (string) $request->validated('idempotency_key'), $request->filled('lock_version') ? $request->integer('lock_version') : null);

        return response()->json(['movement' => ['id' => $movement->id, 'document_number' => $movement->document_number, 'quantity' => Quantity::format($movement->quantity_milli)]], 201);
    }

    public function release(StockDecisionRequest $request, Order $order, StockReservation $reservation, OrderMaterials $materials): JsonResponse
    {
        $released = $materials->release($this->actor($request), $order, $reservation, (string) $request->validated('reason'), $request->version());

        return response()->json(['reservation' => OrderStock::reservation($released->load(['location', 'item']))]);
    }
}

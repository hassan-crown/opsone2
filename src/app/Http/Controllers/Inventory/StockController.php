<?php

namespace App\Http\Controllers\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\ManageStockSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Requests\Inventory\StockItemRequest;
use App\Http\Requests\Inventory\StockSettingsRequest;
use App\Inventory\MovementKind;
use App\Inventory\Quantity;
use App\Inventory\StockAccess;
use App\Inventory\StockItems;
use App\Inventory\StockPresenter;
use App\Models\Inventory\StockLocation;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * StockBalances / StockMovements / StockAdjust (FUL-01, FUL-04; P-48, P-49) and the stock settings (Q-43 a, P-02).
 */
class StockController extends Controller
{
    use StockScreens;

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(StockAccess::any($actor), 403);
        $data = $request->validate(['location_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'], 'low' => ['nullable', 'boolean']]);

        return $this->screen($request, 'stock/index', StockPresenter::balances($actor, isset($data['location_id']) ? (int) $data['location_id'] : null,
            trim((string) ($data['q'] ?? '')), (bool) ($data['low'] ?? false)) + ['items' => StockPresenter::trackable()]);
    }

    public function movements(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(StockAccess::historyLocations($actor)->exists(), 403);
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::enum(MovementKind::class)],
        ]);

        return $this->screen($request, 'stock/movements', StockPresenter::movements($actor, [
            'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
            'item_id' => isset($data['item_id']) ? (int) $data['item_id'] : null,
            'kind' => $data['kind'] ?? null,
        ]));
    }

    public function ready(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P48), 403);

        return $this->screen($request, 'stock/ready', StockPresenter::readyReservations($actor));
    }

    public function items(Request $request): JsonResponse
    {
        abort_unless(StockAccess::any($this->actor($request)), 403);
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        return response()->json(['items' => StockPresenter::trackable(trim($q))]);
    }

    public function adjust(StockAdjustmentRequest $request, AdjustStock $adjust, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $location = StockLocation::query()->findOrFail($request->integer('location_id'));
        $payload = $request->safe()->except('idempotency_key');
        $done = $idempotency->run('stock.adjust', $actor, (string) $request->validated('idempotency_key'), $payload, function () use ($adjust, $actor, $location, $request) {
            $movement = $adjust->handle($actor, $location, (string) $request->validated('item_type'), $request->integer('item_id'),
                (string) $request->validated('direction'), Quantity::parse((string) $request->validated('quantity')), (string) $request->validated('reason'));

            return ['id' => $movement->id, 'document_number' => $movement->document_number, 'on_hand' => Quantity::format($movement->on_hand_after)];
        });

        return response()->json(['movement' => $done['result']], $done['replayed'] ? 200 : 201);
    }

    public function updateItem(StockItemRequest $request, ManageStockSetup $setup): JsonResponse
    {
        $item = $setup->updateItem($this->actor($request), (string) $request->validated('item_type'), $request->integer('item_id'),
            $request->filled('unit') ? (string) $request->validated('unit') : null, $request->reorderLevelMilli());

        return response()->json(['item' => StockItems::describe($item)]);
    }

    public function updateSettings(StockSettingsRequest $request, ManageStockSetup $setup): JsonResponse
    {
        $settings = $setup->updateSettings($this->actor($request), array_values((array) $request->validated('units')), $request->boolean('low_stock_alerts'));

        return response()->json(['settings' => ['units' => $settings->enabled_units, 'low_stock_alerts' => $settings->low_stock_alerts]]);
    }
}

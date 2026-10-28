<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\StateMachines\M25Transfer\T00CreateTransfer;
use App\Actions\StateMachines\M25Transfer\T01DispatchTransfer;
use App\Actions\StateMachines\M25Transfer\T02ReceiveTransfer;
use App\Actions\StateMachines\M25Transfer\T03CancelTransfer;
use App\Actions\StateMachines\M25Transfer\T04InstantTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockDecisionRequest;
use App\Http\Requests\Inventory\StockLinesRequest;
use App\Inventory\StockAccess;
use App\Inventory\StockPresenter;
use App\Models\Inventory\StockTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * StockTransfer (FUL-04, M-25, P-50): draft, exit (in transit), arrival, withdrawal before exit, or instant.
 */
class StockTransferController extends Controller
{
    use StockScreens;

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(StockAccess::any($actor), 403);

        return $this->screen($request, 'stock/transfers', StockPresenter::transfers($actor));
    }

    public function store(StockLinesRequest $request, T00CreateTransfer $create): JsonResponse
    {
        $actor = $this->actor($request);
        $transfer = $create->handle($actor, $request->integer('from_location_id'), $request->integer('to_location_id'),
            array_map(fn (array $l) => ['item_type' => $l['item_type'], 'item_id' => $l['item_id'], 'quantity_milli' => $l['quantity_milli']], $request->lines()),
            $request->filled('note') ? (string) $request->validated('note') : null, $request->boolean('instant'));

        return response()->json(['transfer' => StockPresenter::transfer($transfer->refresh()->load(['from', 'to']), $actor)], 201);
    }

    public function dispatch(StockDecisionRequest $request, StockTransfer $transfer, T01DispatchTransfer $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $transfer, $request->version()));
    }

    public function receive(StockDecisionRequest $request, StockTransfer $transfer, T02ReceiveTransfer $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $transfer, $request->version()));
    }

    public function instant(StockDecisionRequest $request, StockTransfer $transfer, T04InstantTransfer $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $transfer, $request->version()));
    }

    public function cancel(StockDecisionRequest $request, StockTransfer $transfer, T03CancelTransfer $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $transfer, $request->version(), $request->filled('reason') ? (string) $request->validated('reason') : null));
    }

    private function done(Request $request, StockTransfer $transfer): JsonResponse
    {
        return response()->json(['transfer' => StockPresenter::transfer($transfer->refresh()->load(['from', 'to']), $this->actor($request))]);
    }
}

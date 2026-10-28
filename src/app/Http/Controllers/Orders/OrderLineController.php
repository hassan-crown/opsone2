<?php

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ManageOrderLines;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\LockVersionRequest;
use App\Http\Requests\Orders\OrderLineRequest;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Orders\OrderPresenter;
use Illuminate\Http\JsonResponse;

/**
 * Add / remove lines of an editable order (P-17). Lines are never edited: change = remove + add.
 */
class OrderLineController extends Controller
{
    public function store(OrderLineRequest $request, Order $order, ManageOrderLines $lines): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $input = collect($request->validated())->except('lock_version')->all();
        $lines->add($actor, $order, (int) $request->validated('lock_version'), $input);

        return response()->json(['order' => OrderPresenter::detail($order->refresh(), $actor)], 201);
    }

    public function destroy(LockVersionRequest $request, Order $order, OrderLine $line, ManageOrderLines $lines): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $lines->remove($actor, $order, $line, (int) $request->validated('lock_version'));

        return response()->json(['order' => OrderPresenter::detail($order->refresh(), $actor)]);
    }
}

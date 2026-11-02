<?php

namespace App\Http\Controllers\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M14Delivery\T04RecordRemark;
use App\Delivery\DeliveryRemarks;
use App\Http\Controllers\Controller;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryRemark;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Comprehensive closure of an order (M-04-T07 / M-05-T10) and the blocking remarks of a delivery (M-14-T04). Every
 * write is an Action/service that authorizes (P-25 / P-18 for the closure, P-52 for remarks) and re-checks the blockers
 * under the order lock; this class only validates the envelope.
 */
final class OrderClosureController extends Controller
{
    public function close(Request $request, Order $order, OrderClosure $closure): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'lock_version' => ['nullable', 'integer']]);
        $done = $closure->close($this->actor($request), $order, $data['reason'], isset($data['lock_version']) ? (int) $data['lock_version'] : null);

        return $this->answer($request, $order, ['order' => ['number' => $done->number, 'status' => $done->status]], __('order.closure.closed'));
    }

    /** Every remark of the order, open first (P-52 on the order); the JSON contract other clients read. */
    public function remarks(Request $request, Order $order): JsonResponse
    {
        Access::authorize($this->actor($request), Permission::P52, $order);

        return response()->json(['remarks' => DeliveryRemarks::forOrder($order)]);
    }

    public function remark(Request $request, DeliveryNote $note, T04RecordRemark $remarks): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['description' => ['required', 'string', 'min:3', 'max:2000']]);
        $remark = $remarks->handle($this->actor($request), $note, $data['description']);

        return $this->answer($request, $note->order, ['remark' => DeliveryRemarks::row($remark->refresh())], __('delivery.remark.recorded'), 201);
    }

    public function resolveRemark(Request $request, DeliveryRemark $remark, T04RecordRemark $remarks): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'min:3', 'max:2000'], 'lock_version' => ['nullable', 'integer']]);
        $done = $remarks->resolve($this->actor($request), $remark, $data['resolution'], isset($data['lock_version']) ? (int) $data['lock_version'] : null);

        return $this->answer($request, $remark->order, ['remark' => DeliveryRemarks::row($done->refresh())], __('delivery.remark.resolved'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function answer(Request $request, Order $order, array $payload, string $message, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload, $status);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('orders.show', $order);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

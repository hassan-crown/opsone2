<?php

namespace App\Http\Controllers\Commissions;

use App\Actions\Commissions\DecideSaleCorrection;
use App\Actions\Commissions\RequestSaleCorrection;
use App\Commissions\CommissionPresenter;
use App\Enums\OrderKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\CommissionDecisionRequest;
use App\Models\Commissions\SaleCorrection;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «صاحب المبيعة وتصحيح العمولة» of an order (SAL-11, SAL-16, P-94/P-97/P-98, Q-37 a): the pinned owner and rate,
 * the accrual history and the correction requests and decisions. Writes answer `{sale: Payload}`.
 */
class OrderSaleController extends Controller
{
    public function __construct(private readonly CommissionPresenter $presenter) {}

    public function show(Request $request, Order $order): Response|JsonResponse
    {
        $viewer = $this->actor($request);
        abort_if($order->kind !== OrderKind::Custom, 404);
        abort_unless(CommissionPresenter::canViewSale($viewer, $order), 403);
        $payload = $this->presenter->sale($order, $viewer);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['sale' => $payload]);
        }

        return Inertia::render('orders/sale', ['order' => OrderPresenter::row($order, $viewer), 'sale' => $payload]);
    }

    public function requestCorrection(CommissionDecisionRequest $request, Order $order, RequestSaleCorrection $action): JsonResponse
    {
        $correction = $action->handle($this->actor($request), $order, $request->body());

        return $this->respond($request, $order, ['correction_id' => $correction->id], 201);
    }

    public function approve(CommissionDecisionRequest $request, SaleCorrection $correction, DecideSaleCorrection $decide): JsonResponse
    {
        $decide->approve($this->actor($request), $correction, $request->lockVersion());

        return $this->respond($request, $correction->order);
    }

    public function reject(CommissionDecisionRequest $request, SaleCorrection $correction, DecideSaleCorrection $decide): JsonResponse
    {
        $decide->reject($this->actor($request), $correction, (string) ($request->body()['reason'] ?? ''), $request->lockVersion());

        return $this->respond($request, $correction->order);
    }

    public function cancel(CommissionDecisionRequest $request, SaleCorrection $correction, DecideSaleCorrection $decide): JsonResponse
    {
        $decide->cancel($this->actor($request), $correction, $request->lockVersion());

        return $this->respond($request, $correction->order);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(Request $request, Order $order, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([...$extra, 'sale' => $this->presenter->sale($order->refresh(), $this->actor($request))], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

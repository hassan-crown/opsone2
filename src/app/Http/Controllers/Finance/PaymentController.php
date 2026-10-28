<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\ReallocatePayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReallocateRequest;
use App\Models\Finance\Payment;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * The allocation of a payment on one order (FIN-06 review, P-38).
 */
class PaymentController extends Controller
{
    use FinanceResponses;

    public function reallocate(ReallocateRequest $request, Payment $payment, ReallocatePayment $reallocate): JsonResponse
    {
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));
        $body = collect($request->body())->except('order_id')->all();
        $reallocate->handle($this->actor($request), $payment, $order, $body, $request->lockVersion(), $request->key());

        return $this->respond($request, $order);
    }
}

<?php

namespace App\Http\Controllers\Settlement;

use App\Actions\StateMachines\M11Payment\T07CorrectPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\CorrectPaymentRequest;
use App\Models\Finance\Payment;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * FinCorrect (M-11-T07, P-41): void or amend a confirmed payment through reversal journals and a correction voucher.
 */
class CorrectionController extends Controller
{
    use SettlementResponses;

    public function store(CorrectPaymentRequest $request, Payment $payment, T07CorrectPayment $action): JsonResponse
    {
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));
        $body = collect($request->body())->except(['evidence', 'order_id'])->all();
        $correction = $action->handle($this->actor($request), $order, $payment, $body, $request->evidence(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order, ['correction_id' => $correction->id, 'number' => $correction->number], 201);
    }
}

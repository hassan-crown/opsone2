<?php

namespace App\Http\Controllers\Settlement;

use App\Actions\StateMachines\M12Refund\T00RequestRefund;
use App\Actions\StateMachines\M12Refund\T01StartRefund;
use App\Actions\StateMachines\M12Refund\T02ConfirmRefund;
use App\Actions\StateMachines\M12Refund\T04RecordRefundFailure;
use App\Actions\StateMachines\M12Refund\T06CancelRefund;
use App\Actions\StateMachines\M12Refund\T07RetryRefund;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\RefundConfirmRequest;
use App\Http\Requests\Settlement\RefundReferenceRequest;
use App\Http\Requests\Settlement\RefundRequest;
use App\Http\Requests\Settlement\SettlementReasonRequest;
use App\Models\Finance\Refund;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * Refunds (M-12, FIN-09): FinRefund (request, P-42) and FinRefundExec (send / confirm with proof / failure / retry,
 * P-43). Every Action authorizes on the order through the Gate.
 */
class RefundController extends Controller
{
    use SettlementResponses;

    public function store(RefundRequest $request, Order $order, T00RequestRefund $action): JsonResponse
    {
        $refund = $action->handle($this->actor($request), $order, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order, ['refund_id' => $refund->id], 201);
    }

    public function start(RefundReferenceRequest $request, Refund $refund, T01StartRefund $action): JsonResponse
    {
        $action->handle($this->actor($request), $refund, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $refund->order);
    }

    public function confirm(RefundConfirmRequest $request, Refund $refund, T02ConfirmRefund $action): JsonResponse
    {
        $body = collect($request->body())->except('proof')->all();
        $refund = $action->handle($this->actor($request), $refund, $body, $request->proof(), $request->lockVersion(), $request->key());

        return $this->respond($request, $refund->order, ['number' => $refund->number]);
    }

    public function fail(SettlementReasonRequest $request, Refund $refund, T04RecordRefundFailure $action): JsonResponse
    {
        $action->handle($this->actor($request), $refund, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->respond($request, $refund->order);
    }

    public function retry(RefundReferenceRequest $request, Refund $refund, T07RetryRefund $action): JsonResponse
    {
        $action->handle($this->actor($request), $refund, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $refund->order);
    }

    public function cancel(SettlementReasonRequest $request, Refund $refund, T06CancelRefund $action): JsonResponse
    {
        $action->handle($this->actor($request), $refund, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->respond($request, $refund->order);
    }
}

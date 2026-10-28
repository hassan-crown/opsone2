<?php

namespace App\Http\Controllers\Finance;

use App\Actions\StateMachines\M10Proof\T01ConfirmProof;
use App\Actions\StateMachines\M10Proof\T02RequestProofCorrection;
use App\Actions\StateMachines\M10Proof\T04CancelProof;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\ReasonRequest;
use App\Http\Requests\Finance\ConfirmProofRequest;
use App\Models\Finance\PaymentProof;
use Illuminate\Http\JsonResponse;

/**
 * Staff decisions on a customer's transfer proof (M-10, P-40): FinReceiptReview, FinReceiptMobile, FinReceiptFix.
 */
class ProofController extends Controller
{
    use FinanceResponses;

    public function confirm(ConfirmProofRequest $request, PaymentProof $proof, T01ConfirmProof $confirm): JsonResponse
    {
        $proof = $confirm->handle($this->actor($request), $proof, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $proof->order, ['payment_id' => $proof->payment_id]);
    }

    public function requestCorrection(ReasonRequest $request, PaymentProof $proof, T02RequestProofCorrection $correction): JsonResponse
    {
        $correction->handle($this->actor($request), $proof, (string) $request->validated('reason'), $request->lockVersion());

        return $this->respond($request, $proof->order);
    }

    public function cancel(ReasonRequest $request, PaymentProof $proof, T04CancelProof $cancel): JsonResponse
    {
        $cancel->handle($this->actor($request), $proof, (string) $request->validated('reason'), $request->lockVersion());

        return $this->respond($request, $proof->order);
    }
}

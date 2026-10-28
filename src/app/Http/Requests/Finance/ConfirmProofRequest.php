<?php

namespace App\Http\Requests\Finance;

use App\Actions\StateMachines\M10Proof\T01ConfirmProof;

/**
 * FinReceiptReview: the amount actually received, its date and reference (or the payment it already is).
 */
class ConfirmProofRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return T01ConfirmProof::rules();
    }
}

<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * Reversal of a debt write-off (P-103, ADR-0034): a mandatory reason. Money-affecting: the idempotency key is required.
 */
class WriteOffReversalRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}

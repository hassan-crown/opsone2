<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * M-12-T01 / T07: the bank or provider reference of the attempt, when known.
 */
class RefundReferenceRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['reference' => ['nullable', 'string', 'max:80']];
    }
}

<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * A reason the record keeps: refund failure or withdrawal, document correction.
 */
class SettlementReasonRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}

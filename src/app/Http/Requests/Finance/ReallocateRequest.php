<?php

namespace App\Http\Requests\Finance;

use App\Actions\Finance\ReallocatePayment;

/**
 * The allocation of one payment on one order, as the person corrected it.
 */
class ReallocateRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['order_id' => ['required', 'integer'], ...ReallocatePayment::rules()];
    }
}

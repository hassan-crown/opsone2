<?php

namespace App\Http\Requests\Finance;

/**
 * The order's payment plan (ChoosePaymentPlan).
 */
class ChoosePlanRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['plan_id' => ['required', 'integer']];
    }
}

<?php

namespace App\Http\Requests\Settlement;

use App\Actions\StateMachines\M12Refund\T00RequestRefund;
use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * FinRefund: the original payment, amount, refund method and reason (M-12 request).
 */
class RefundRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return T00RequestRefund::rules();
    }
}

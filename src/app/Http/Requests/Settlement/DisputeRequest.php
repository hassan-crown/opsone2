<?php

namespace App\Http\Requests\Settlement;

use App\Actions\StateMachines\M39Dispute\T00OpenDispute;
use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * FinDispute: the movement (payment, refund or due), subject, amount, responsible person and reason (M-39).
 */
class DisputeRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return T00OpenDispute::rules();
    }
}

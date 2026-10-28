<?php

namespace App\Http\Requests\Finance;

use App\Actions\Finance\TransferCredit;

/**
 * FinAllocate: move credit to another order of the same customer.
 */
class TransferRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return TransferCredit::rules();
    }
}

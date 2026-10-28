<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * M-39-T02: what the external party answered.
 */
class DisputeNoteRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }
}

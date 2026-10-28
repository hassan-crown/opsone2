<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * M-39-T01: the external party the dispute waits for, and a note.
 */
class DisputeAwaitRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['external_party' => ['required', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:1000']];
    }
}

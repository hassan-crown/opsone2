<?php

namespace App\Http\Requests\Agreements;

use App\Agreements\QuoteInput;

class QuoteDraftRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return [...QuoteInput::rules(), 'description' => ['nullable', 'string', 'max:2000']];
    }
}

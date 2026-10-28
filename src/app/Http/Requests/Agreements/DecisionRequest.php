<?php

namespace App\Http\Requests\Agreements;

class DecisionRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:1000']];
    }
}

<?php

namespace App\Http\Requests\Agreements;

class ChangeCreateRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return ['description' => ['required', 'string', 'max:1000']];
    }
}

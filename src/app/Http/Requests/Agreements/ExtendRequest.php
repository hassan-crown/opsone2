<?php

namespace App\Http\Requests\Agreements;

class ExtendRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    protected function bodyRules(): array
    {
        return ['validity_days' => ['required', 'integer', 'min:1', 'max:365']];
    }
}

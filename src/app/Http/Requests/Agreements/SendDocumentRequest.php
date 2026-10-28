<?php

namespace App\Http\Requests\Agreements;

class SendDocumentRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return ['validity_days' => ['nullable', 'integer', 'min:1', 'max:365']];
    }
}

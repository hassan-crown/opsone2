<?php

namespace App\Http\Requests\Agreements;

/**
 * Cancel, withdraw, revision and rejection: a reason, the version read; not money-affecting.
 */
class ReasonRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    protected function bodyRules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}

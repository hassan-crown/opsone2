<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * A write that needs only the envelope and a reason (cancel the remainder).
 */
class ReasonRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}

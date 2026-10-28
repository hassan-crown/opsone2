<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * Withdraw a draft: a reason.
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

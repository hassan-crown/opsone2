<?php

namespace App\Http\Requests\Cancellation;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * A reason with the version read: rejecting a request (M-34-T04) or resolving a settlement item.
 */
class DecisionReasonRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'status' => ['nullable', 'in:done,dropped'],
        ];
    }
}

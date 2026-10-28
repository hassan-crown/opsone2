<?php

namespace App\Http\Requests\Returns;

/**
 * Reject or withdraw: a reason.
 */
class ReturnReasonRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}

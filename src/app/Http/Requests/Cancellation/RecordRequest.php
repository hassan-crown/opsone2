<?php

namespace App\Http\Requests\Cancellation;

/**
 * Recording a request without deciding it (M-34-T01): no fingerprint, the key still required.
 */
class RecordRequest extends CancellationRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return ['hash' => ['nullable', 'string', 'size:64']] + parent::bodyRules();
    }
}

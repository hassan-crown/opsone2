<?php

namespace App\Http\Requests\Cancellation;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Models\Agreements\CustomerDecision;

/**
 * Body of a cancellation preview / request / decision (CancelReview): the scope and, for a partial one, the lines;
 * the request fields (source, channel, date, reason) when it is recorded; the reviewed fingerprint when it is applied.
 * Domain rules (cancellable quantities, amounts only with P-37) are checked by the Actions.
 */
class CancellationRequest extends AgreementWriteRequest
{
    /** Previews do not write: no key, no fingerprint. */
    protected bool $preview = false;

    protected function prepareForValidation(): void
    {
        $this->requiresKey = ! $this->preview;
        parent::prepareForValidation();
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'scope' => ['required', 'in:full,partial'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.ref' => ['required', 'string', 'max:12'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'retained' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'in:external,customer_account'],
            'channel' => ['nullable', 'in:'.implode(',', CustomerDecision::CHANNELS)],
            'requested_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'hash' => [$this->preview ? 'nullable' : 'required', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancellationData(): array
    {
        return collect($this->body())->except(['hash'])->all();
    }

    public function hash(): string
    {
        return (string) $this->validated('hash');
    }
}

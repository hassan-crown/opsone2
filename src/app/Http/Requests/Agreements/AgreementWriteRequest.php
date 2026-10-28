<?php

namespace App\Http\Requests\Agreements;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the agreement write requests (docs/p6-finance-api.md): every money-affecting write carries an
 * `idempotency_key` (field or `Idempotency-Key` header; one per screen action, reused on retry) and the
 * `lock_version` the screen read. Authorization happens in the Actions, on the record, through the central Gate.
 */
class AgreementWriteRequest extends FormRequest
{
    /** Writes that may skip the key (not money-affecting). */
    protected bool $requiresKey = true;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => [$this->requiresKey ? 'required' : 'nullable', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            ...$this->bodyRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [];
    }

    public function key(): ?string
    {
        $key = $this->validated('idempotency_key');

        return $key === null ? null : (string) $key;
    }

    public function lockVersion(): ?int
    {
        $version = $this->validated('lock_version');

        return $version === null ? null : (int) $version;
    }

    /**
     * The validated body without the envelope fields.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return collect($this->validated())->except(['idempotency_key', 'lock_version'])->all();
    }
}

<?php

namespace App\Http\Requests\Flow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The facility flow endpoints (M-23). Authorization is the Actions' own (P-20 / P-21 on every write); the shape of
 * the definition is FlowCheck's business, so only its container is validated here.
 */
class FlowRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'saveDraft' => ['lock_version' => ['required', 'integer', 'min:1'], 'stages' => ['required', 'array', 'max:30']],
            'check' => ['stages' => ['required', 'array', 'max:30']],
            'chooseTemplate' => [
                'lock_version' => ['required', 'integer', 'min:1'],
                'template' => ['required', 'string', 'max:30'],
                'discard_draft' => ['sometimes', 'boolean'],
            ],
            default => ['lock_version' => ['required', 'integer', 'min:1']],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stages(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values((array) $this->input('stages', []));
    }
}

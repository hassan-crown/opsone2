<?php

namespace App\Http\Requests\Rules;

use App\Actions\Rules\PublishConfigRelease;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publish, roll back or discard the draft rules (ADR-0040): the release the writer reviewed (`base`), the draft they
 * reviewed (`draft_hash`, publish only) and optional notes. Authorization is the action's (P-104).
 */
class ConfigReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base' => ['required', 'integer', 'min:0'],
            'draft_hash' => [$this->routeIs('rules.releases.publish') ? 'required' : 'nullable', 'string', 'size:64'],
            'notes' => ['nullable', 'string', 'max:'.PublishConfigRelease::MAX_NOTES],
        ];
    }
}

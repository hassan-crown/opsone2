<?php

namespace App\Http\Requests\Rules;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The sample a draft is tried on (ADR-0040): an agreed value as text («37500.00») and the language of the texts.
 * Reading the rules is enough (P-104 or P-02, checked in the controller).
 */
class ConfigPreviewRequest extends FormRequest
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
            'value' => ['required', 'string', 'max:20', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'locale' => ['required', 'in:ar,en'],
        ];
    }
}

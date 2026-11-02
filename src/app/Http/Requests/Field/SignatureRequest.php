<?php

namespace App\Http\Requests\Field;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's acceptance: typed name, drawn signature (PNG data URL, checked by FieldVisits) and consent. Shared
 * by the technician's phone and the customer's workspace.
 */
class SignatureRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signer_name' => ['required', 'string', 'min:2', 'max:120'],
            'signature' => ['required', 'string', 'max:420000'],
            'consent' => ['accepted'],
        ];
    }
}

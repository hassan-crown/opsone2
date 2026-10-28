<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public contact form (P-72): short fields, at least one way to reach the visitor, no attachments.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'email' => ['nullable', 'email:rfc', 'max:190', 'required_without:mobile'],
            'need' => ['required', 'string', 'min:3', 'max:2000'],
            'submission' => ['required', 'uuid'],
            'form_token' => ['required', 'string'],
            'website' => ['nullable', 'string', 'max:255'], // honeypot
        ];
    }
}

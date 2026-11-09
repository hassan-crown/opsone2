<?php

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

class AcceptLeadFieldsRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'replace_name' => ['nullable', 'boolean'],
            'product' => ['nullable', 'string', 'max:300'],
            'district' => ['nullable', 'string', 'max:120'],
            'budget' => ['nullable', 'string', 'max:120'],
            'preferred_time' => ['nullable', 'string', 'max:160'],
        ];
    }
}

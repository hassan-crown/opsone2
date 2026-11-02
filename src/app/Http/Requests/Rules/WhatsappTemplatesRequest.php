<?php

namespace App\Http\Requests\Rules;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approved WhatsApp template names per event (ADR-0037). Authorization is the action's (P-104).
 */
class WhatsappTemplatesRequest extends FormRequest
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
        return ['templates' => ['present', 'array'], 'templates.*' => ['nullable', 'string', 'max:250']];
    }
}

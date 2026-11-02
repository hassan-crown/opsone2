<?php

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Save or preview the facility's purchase-order document (P-45, ADR-0038). Only the container is validated here;
 * PurchaseDocumentLayout validates and normalizes the content.
 */
class PurchaseDocumentRequest extends FormRequest
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
            'layout' => ['required', 'array'],
            'email' => [$this->routeIs('*.preview') ? 'nullable' : 'required', 'array'],
            'version' => [$this->routeIs('*.preview') ? 'nullable' : 'required', 'integer', 'min:0'],
            'locale' => ['nullable', 'in:ar,en'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function layout(): array
    {
        return (array) $this->validated('layout');
    }

    /**
     * @return array<string, mixed>
     */
    public function email(): array
    {
        return (array) $this->validated('email', []);
    }
}

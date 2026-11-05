<?php

namespace App\Http\Requests\FieldSales;

use Illuminate\Foundation\Http\FormRequest;

/** Dispatching a lead or appointment request to a field sales rep (P-105, ADR-0041). */
class DispatchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rep_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\FieldSales;

use Illuminate\Foundation\Http\FormRequest;

/** The rep's notes on a sales visit (ADR-0041). */
class SalesNoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['present', 'nullable', 'string', 'max:5000'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

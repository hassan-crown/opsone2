<?php

namespace App\Http\Requests\FieldSales;

use Illuminate\Foundation\Http\FormRequest;

/** A supervisor sets a rep's discount limit in basis points (ADR-0041). */
class RepDiscountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit_bp' => ['required', 'integer', 'between:0,10000'],
        ];
    }
}

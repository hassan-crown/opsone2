<?php

namespace App\Http\Requests\FieldSales;

use Illuminate\Foundation\Http\FormRequest;

/** The visit's outcome, which completes the appointment (M-16-T05). */
class SalesFinishRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }
}

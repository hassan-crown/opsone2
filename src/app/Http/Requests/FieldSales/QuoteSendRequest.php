<?php

namespace App\Http\Requests\FieldSales;

use Illuminate\Foundation\Http\FormRequest;

/** Sending the quote/contract to the customer from the field (M-08-T01). */
class QuoteSendRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer'],
            'validity_days' => ['nullable', 'integer', 'between:1,90'],
        ];
    }
}

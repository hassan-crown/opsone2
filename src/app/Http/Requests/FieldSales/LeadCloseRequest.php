<?php

namespace App\Http\Requests\FieldSales;

use App\Enums\InterestCloseReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Closing a lead from the field (M-03-T03). */
class LeadCloseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(InterestCloseReason::values())],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

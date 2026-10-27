<?php

namespace App\Http\Requests\Orders;

use App\Enums\OrderLineType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add one line to an editable order (P-17, authorized on the record by ManageOrderLines).
 */
class OrderLineRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            ...OrderLineRules::rules('', OrderLineType::cases()),
        ];
    }
}

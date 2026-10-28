<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    use QuantityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer'],
            'item_type' => $this->itemTypeRule(),
            'item_id' => ['required', 'integer'],
            'direction' => ['required', 'in:in,out'],
            'quantity' => $this->quantityRule(),
            'reason' => ['required', 'string', 'max:500'],
            // A double click or a network retry posts the adjustment once (one SA number, one movement).
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
        ];
    }
}

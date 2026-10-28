<?php

namespace App\Http\Requests\Inventory;

use App\Inventory\StockUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'units' => ['required', 'array', 'max:20'],
            'units.*' => ['string', Rule::enum(StockUnit::class)],
            'low_stock_alerts' => ['required', 'boolean'],
        ];
    }
}

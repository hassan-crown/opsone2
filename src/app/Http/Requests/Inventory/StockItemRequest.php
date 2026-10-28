<?php

namespace App\Http\Requests\Inventory;

use App\Inventory\StockUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockItemRequest extends FormRequest
{
    use QuantityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_type' => $this->itemTypeRule(),
            'item_id' => ['required', 'integer'],
            'unit' => ['nullable', Rule::enum(StockUnit::class)],
            'reorder_level' => ['nullable', ...array_slice($this->quantityRule(true), 1)],
        ];
    }

    public function reorderLevelMilli(): ?int
    {
        return $this->filled('reorder_level') ? $this->milli('reorder_level') : null;
    }
}

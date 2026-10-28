<?php

namespace App\Http\Requests\Inventory;

use App\Inventory\Quantity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lines of a transfer (`quantity`) or a count (`counted`, zero allowed).
 */
class StockLinesRequest extends FormRequest
{
    use QuantityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $count = $this->routeIs('stock.counts.*');

        return [
            'location_id' => [$count && $this->isMethod('post') ? 'required' : 'nullable', 'integer'],
            'from_location_id' => [! $count ? 'required' : 'nullable', 'integer'],
            'to_location_id' => [! $count ? 'required' : 'nullable', 'integer'],
            'instant' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => [$count && $this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:500'],
            'lock_version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_type' => $this->itemTypeRule(),
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => $count ? ['nullable'] : $this->quantityRule(),
            'lines.*.counted' => $count ? $this->quantityRule(true) : ['nullable'],
        ];
    }

    /**
     * @return list<array{item_type: string, item_id: int, quantity_milli: int, counted_milli: int}>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $l) => [
            'item_type' => (string) $l['item_type'],
            'item_id' => (int) $l['item_id'],
            'quantity_milli' => isset($l['quantity']) ? Quantity::parse((string) $l['quantity']) : 0,
            'counted_milli' => isset($l['counted']) ? Quantity::parse((string) $l['counted']) : 0,
        ], (array) $this->validated('lines')));
    }
}

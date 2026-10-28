<?php

namespace App\Http\Requests\Inventory;

use App\Inventory\Quantity;
use App\Models\Inventory\StockItem;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Shared rules: a quantity is decimal text with up to three places (Q-43 a), parsed to thousandths server-side.
 */
trait QuantityRules
{
    /**
     * @return list<mixed>
     */
    protected function quantityRule(bool $allowZero = false): array
    {
        return ['required', function (string $attribute, mixed $value, Closure $fail) use ($allowZero) {
            $ok = $allowZero
                ? (is_string($value) || is_int($value)) && preg_match('/^\d{1,12}(\.\d{1,3})?$/', trim((string) $value)) === 1
                : Quantity::isValid($value);
            if (! $ok) {
                $fail(__('stock.validation.quantity_format'));
            }
        }];
    }

    /**
     * @return list<mixed>
     */
    protected function itemTypeRule(): array
    {
        return ['required', Rule::in(StockItem::TYPES)];
    }

    protected function milli(string $key): int
    {
        return Quantity::parse((string) $this->input($key));
    }
}

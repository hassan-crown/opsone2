<?php

namespace App\Http\Requests\Orders;

use App\Enums\OrderLineType;
use Illuminate\Validation\Rule;

/**
 * Shape of one order line in a request. Catalog facts (selectable item, option keys, whole quantities for ready
 * goods) are checked against the database by App\Orders\OrderLineBuilder. Quantities are plain decimals with at
 * most three places (the column's scale), so nothing is silently rounded to zero.
 */
final class OrderLineRules
{
    /**
     * @param  list<OrderLineType>  $types
     * @return array<string, mixed>
     */
    public static function rules(string $prefix, array $types): array
    {
        return [
            $prefix.'type' => ['required', Rule::in(array_map(fn (OrderLineType $t) => $t->value, $types))],
            $prefix.'product_id' => ['nullable', 'required_if:'.$prefix.'type,custom_product', 'integer'],
            $prefix.'variant_id' => ['nullable', 'required_if:'.$prefix.'type,ready_variant', 'integer'],
            $prefix.'quantity' => ['nullable', 'required_if:'.$prefix.'type,ready_variant', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999'],
            $prefix.'unit' => ['nullable', 'string', 'max:16'],
            $prefix.'description' => ['nullable', 'required_if:'.$prefix.'type,custom_need', 'string', 'max:2000'],
            $prefix.'options' => ['nullable', 'array', 'max:30'],
            $prefix.'options.*.key' => ['required', 'string', 'max:40'],
            $prefix.'options.*.value' => ['required', 'string', 'max:200'],
        ];
    }
}

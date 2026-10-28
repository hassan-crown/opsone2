<?php

namespace App\Http\Requests\Returns;

/**
 * ReturnNew: delivered lines and quantities asked back, with a reason.
 */
class StoreReturnRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => self::quantityRule(),
        ];
    }
}

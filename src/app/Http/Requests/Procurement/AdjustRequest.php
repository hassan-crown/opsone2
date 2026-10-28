<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Procurement\Quantity;
use Closure;

/**
 * Change the ordered quantity of open lines with a reason (the explicit agreement behind an over-supply).
 */
class AdjustRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'changes' => ['required', 'array', 'min:1', 'max:100'],
            'changes.*.line_id' => ['required', 'integer'],
            'changes.*.quantity' => ['required', function (string $attribute, mixed $value, Closure $fail) {
                if (! Quantity::isValidInput(is_numeric($value) ? (string) $value : $value) || Quantity::fromInput(trim((string) $value)) < 1) {
                    $fail(__('procurement.error.quantity'));
                }
            }],
        ];
    }

    /**
     * @return list<array{line_id: int, quantity_milli: int}>
     */
    public function changes(): array
    {
        return array_map(fn (array $c) => ['line_id' => (int) $c['line_id'], 'quantity_milli' => Quantity::fromInput(trim((string) $c['quantity']))],
            array_values((array) $this->validated('changes')));
    }
}

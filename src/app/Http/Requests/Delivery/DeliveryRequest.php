<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Inventory\Quantity;
use Closure;

/**
 * A delivery draft: per order line the actual quantity as decimal text. The key is required — a double submit must not
 * create two drafts.
 */
class DeliveryRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && ! Quantity::isValid(is_numeric($value) ? (string) $value : $value)) {
                    $fail(__('delivery.error.quantity'));
                }
            }],
        ];
    }

    /**
     * @return list<array{order_line_id: int, quantity_milli: int}>
     */
    public function lineData(): array
    {
        return array_map(fn (array $l) => [
            'order_line_id' => (int) $l['order_line_id'],
            'quantity_milli' => ($l['quantity'] ?? '') === '' || $l['quantity'] === null ? 0 : Quantity::parse(trim((string) $l['quantity'])),
        ], array_values((array) $this->validated('lines')));
    }

    public function noteText(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}

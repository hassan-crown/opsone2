<?php

namespace App\Http\Requests\Returns;

/**
 * ReturnReceive: the receiving location, the quantities that arrived per line and — when `inspect` — the inspection
 * outcome of each (good / damaged / rejected) recorded in the same step.
 */
class ReceiveReturnRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'location_id' => ['required', 'integer'],
            'inspect' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => self::quantityRule(),
            'lines.*.good' => self::quantityRule(),
            'lines.*.damaged' => self::quantityRule(),
            'lines.*.rejected' => self::quantityRule(),
        ];
    }

    /**
     * @return list<array{order_line_id: int, quantity_milli: int, good_milli: int|null, damaged_milli: int|null, rejected_milli: int|null}>
     */
    public function receiptLines(): array
    {
        return array_map(fn (array $l) => [
            'order_line_id' => (int) $l['order_line_id'],
            'quantity_milli' => self::milli($l['quantity'] ?? null),
            'good_milli' => self::milliOrNull($l['good'] ?? null),
            'damaged_milli' => self::milliOrNull($l['damaged'] ?? null),
            'rejected_milli' => self::milliOrNull($l['rejected'] ?? null),
        ], array_values((array) $this->validated('lines')));
    }

    public function noteText(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}

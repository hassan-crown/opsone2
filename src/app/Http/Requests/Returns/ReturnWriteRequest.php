<?php

namespace App\Http\Requests\Returns;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Inventory\Quantity;
use Closure;
use InvalidArgumentException;

/**
 * Base of the return writes: the idempotency key (required — stock and money must move once) and the return's
 * `lock_version`; quantities are decimal text in the item's unit, parsed to thousandths.
 */
abstract class ReturnWriteRequest extends AgreementWriteRequest
{
    /**
     * @return list<mixed>
     */
    protected static function quantityRule(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || $value === '') {
                return;
            }
            // Zero is allowed (a line left out, or nothing in one inspection outcome); negatives and junk are not.
            try {
                Quantity::parse(is_numeric($value) ? (string) $value : (is_string($value) ? $value : ''));
            } catch (InvalidArgumentException) {
                $fail(__('returns.error.quantity'));
            }
        }];
    }

    protected static function milli(mixed $value): int
    {
        return $value === null || $value === '' ? 0 : Quantity::parse(trim((string) $value));
    }

    protected static function milliOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : Quantity::parse(trim((string) $value));
    }

    /**
     * @return list<array{order_line_id: int, quantity_milli: int}>
     */
    public function lineData(): array
    {
        return array_map(fn (array $l) => [
            'order_line_id' => (int) $l['order_line_id'],
            'quantity_milli' => self::milli($l['quantity'] ?? null),
        ], array_values((array) $this->validated('lines')));
    }

    public function reasonText(): string
    {
        return (string) $this->validated('reason');
    }
}

<?php

namespace App\Http\Requests\Returns;

use App\Money\Money;
use Closure;
use Throwable;

/**
 * ReturnDecision (accept): optional accepted quantities per line (all requested when omitted), the reason and an
 * optional expected settlement amount (SAR text, ≤ the value of the accepted goods).
 */
class AcceptReturnRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'settlement' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                try {
                    Money::fromInput((string) $value);
                } catch (Throwable) {
                    $fail(__('returns.error.amount'));
                }
            }],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => self::quantityRule(),
        ];
    }

    /**
     * @return list<array{order_line_id: int, quantity_milli: int}>|null
     */
    public function acceptedLines(): ?array
    {
        return $this->validated('lines') === null ? null : $this->lineData();
    }

    public function settlementMinor(): ?int
    {
        $value = $this->validated('settlement');

        return $value === null || trim((string) $value) === '' ? null : Money::fromInput((string) $value)->minor;
    }
}

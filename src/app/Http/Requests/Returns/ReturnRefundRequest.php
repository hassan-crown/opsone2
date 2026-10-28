<?php

namespace App\Http\Requests\Returns;

use App\Enums\PaymentMethod;
use App\Money\Money;
use Closure;
use Throwable;

/**
 * Ask the return's refund (M-12 request): the original payment, the amount (≤ the return's settlement), the method and
 * a reason.
 */
class ReturnRefundRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'payment_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                try {
                    Money::fromInput((string) $value);
                } catch (Throwable) {
                    $fail(__('returns.error.amount'));
                }
            }],
            'method' => ['required', 'in:'.implode(',', PaymentMethod::manual())],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array{payment_id: int, amount_minor: int, method: string, reason: string}
     */
    public function refundData(): array
    {
        return [
            'payment_id' => (int) $this->validated('payment_id'),
            'amount_minor' => Money::fromInput((string) $this->validated('amount'))->minor,
            'method' => (string) $this->validated('method'),
            'reason' => (string) $this->validated('reason'),
        ];
    }
}

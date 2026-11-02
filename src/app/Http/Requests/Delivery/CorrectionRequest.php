<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * Correct a confirmed delivery (M-14-T03): note lines with the quantity taken back, where ready stock goes and a reason.
 * Stock-moving: the idempotency key is required.
 */
class CorrectionRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'destination' => ['nullable', 'in:stock,reservation'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array{lines: list<array{line_id: int|string, quantity: string}>, destination: string|null, reason: string}
     */
    public function correctionData(): array
    {
        /** @var list<array{line_id: int|string, quantity: string}> $lines */
        $lines = array_values((array) $this->validated('lines'));

        return ['lines' => $lines, 'destination' => $this->validated('destination'), 'reason' => (string) $this->validated('reason')];
    }
}

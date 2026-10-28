<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Procurement\Quantity;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Record a supplier receipt (P-47): per line the accepted and rejected quantities as decimal text, plus the delivery
 * proofs. The key is required — a double submit must not receive twice.
 */
class ReceiveRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        $quantity = function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && $value !== '' && ! Quantity::isValidInput(is_numeric($value) ? (string) $value : $value)) {
                $fail(__('procurement.error.quantity'));
            }
        };

        return [
            'location_id' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.accepted' => ['nullable', $quantity],
            'lines.*.rejected' => ['nullable', $quantity],
            'lines.*.reject_reason' => ['nullable', 'string', 'max:500'],
            'proofs' => ['nullable', 'array', 'max:5'],
            'proofs.*' => ['file'],
        ];
    }

    /**
     * @return array{location_id: int, received_on: string, reference: string|null, note: string|null, lines: list<array{line_id: int, accepted_milli: int, rejected_milli: int, reject_reason?: string|null}>}
     */
    public function receiptData(): array
    {
        $body = $this->body();
        $milli = fn (mixed $v): int => $v === null || $v === '' ? 0 : Quantity::fromInput(trim((string) $v));

        return [
            'location_id' => (int) $body['location_id'],
            'received_on' => (string) $body['received_on'],
            'reference' => isset($body['reference']) && trim((string) $body['reference']) !== '' ? trim((string) $body['reference']) : null,
            'note' => isset($body['note']) && trim((string) $body['note']) !== '' ? trim((string) $body['note']) : null,
            'lines' => array_map(fn (array $l) => [
                'line_id' => (int) $l['line_id'], 'accepted_milli' => $milli($l['accepted'] ?? null),
                'rejected_milli' => $milli($l['rejected'] ?? null), 'reject_reason' => $l['reject_reason'] ?? null,
            ], array_values((array) $body['lines'])),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function proofs(): array
    {
        /** @var list<UploadedFile> */
        return array_values(array_filter((array) $this->file('proofs')));
    }

    public function body(): array
    {
        return collect($this->validated())->except(['idempotency_key', 'lock_version', 'proofs'])->all();
    }
}

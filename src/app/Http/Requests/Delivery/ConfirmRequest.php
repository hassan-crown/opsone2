<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * Record the delivery (Q-44 a): receiver name, date, note and at least one proof file or photo.
 */
class ConfirmRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'delivered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'receiver_name' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'proofs' => ['required', 'array', 'min:1', 'max:5'],
            'proofs.*' => ['file'],
        ];
    }

    /**
     * @return array{delivered_on: string, receiver_name: string, note: string|null}
     */
    public function deliveryData(): array
    {
        return [
            'delivered_on' => (string) $this->validated('delivered_on'),
            'receiver_name' => (string) $this->validated('receiver_name'),
            'note' => $this->validated('note') === null ? null : (string) $this->validated('note'),
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

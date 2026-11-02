<?php

namespace App\Http\Requests\Finance;

use App\Files\Rules\AllowedAttachment;
use App\Http\Requests\Agreements\AgreementWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * Debt write-off (P-103, ADR-0035): the amount the screen showed as owed, a mandatory reason and an optional file.
 * Money-affecting: the idempotency key is required.
 */
class WriteOffRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'file' => ['nullable', 'file', new AllowedAttachment],
        ];
    }

    public function upload(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }
}

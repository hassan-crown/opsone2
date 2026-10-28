<?php

namespace App\Http\Requests\Commissions;

use App\Http\Requests\Finance\FinanceWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * Envelope of the commission writes (docs/p6-finance-api.md «6d»): `idempotency_key` on payout writes, the
 * `lock_version` the screen read, an optional `proof` file. The Actions validate the body.
 */
class CommissionWriteRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['proof' => ['nullable', 'file']];
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->except(['idempotency_key', 'lock_version', 'proof', '_method']);
    }

    public function proof(): ?UploadedFile
    {
        $file = $this->file('proof');

        return $file instanceof UploadedFile ? $file : null;
    }
}

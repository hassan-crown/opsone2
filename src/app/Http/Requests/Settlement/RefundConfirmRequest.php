<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * FinRefundExec (multipart): the bank or provider reference, an optional note and the external proof of the payout.
 */
class RefundConfirmRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['reference' => ['required', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:1000'], 'proof' => ['required', 'file']];
    }

    public function proof(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('proof');
    }
}

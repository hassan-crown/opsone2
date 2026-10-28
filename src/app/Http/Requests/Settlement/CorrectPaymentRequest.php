<?php

namespace App\Http\Requests\Settlement;

use App\Actions\StateMachines\M11Payment\T07CorrectPayment;
use App\Http\Requests\Finance\FinanceWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * FinCorrect (multipart): void or amend, the reason, the right figures for an amendment and optional evidence.
 */
class CorrectPaymentRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return [...T07CorrectPayment::rules(), 'order_id' => ['required', 'integer'], 'evidence' => ['nullable', 'file']];
    }

    public function evidence(): ?UploadedFile
    {
        $file = $this->file('evidence');

        return $file instanceof UploadedFile ? $file : null;
    }
}

<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Finance\FinanceWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * FinDisputeResolve (multipart): the outcome, what was verified and the proof of the treatment.
 */
class DisputeResolveRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return ['outcome' => ['required', 'string', 'max:20'], 'resolution' => ['required', 'string', 'max:2000'], 'proof' => ['required', 'file']];
    }

    public function proof(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('proof');
    }
}

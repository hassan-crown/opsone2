<?php

namespace App\Http\Requests\Finance;

use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use Illuminate\Http\UploadedFile;

/**
 * FinRecordPayment (multipart): amount, method, date, reference, note, optional split over the customer's orders,
 * `acknowledge_similar`, and the evidence file (type and size checked by the attachment service; optional for cash
 * only when the owner turned `cash_proof_required` off — the Action decides).
 */
class RecordPaymentRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return [...T01RecordPayment::rules(), 'evidence' => ['nullable', 'file']];
    }

    public function evidence(): ?UploadedFile
    {
        $file = $this->file('evidence');

        return $file instanceof UploadedFile ? $file : null;
    }
}

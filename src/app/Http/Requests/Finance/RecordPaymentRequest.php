<?php

namespace App\Http\Requests\Finance;

use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use Illuminate\Http\UploadedFile;

/**
 * FinRecordPayment (multipart): amount, method, date, reference, note, optional split over the customer's orders,
 * `acknowledge_similar`, and the evidence file (type and size checked by the attachment service).
 */
class RecordPaymentRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return [...T01RecordPayment::rules(), 'evidence' => ['required', 'file']];
    }

    public function evidence(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('evidence');
    }
}

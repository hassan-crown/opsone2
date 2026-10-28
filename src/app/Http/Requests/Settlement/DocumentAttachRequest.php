<?php

namespace App\Http\Requests\Settlement;

use App\Actions\StateMachines\M40Document\T01AttachDocument;
use App\Http\Requests\Finance\FinanceWriteRequest;
use Illuminate\Http\UploadedFile;

/**
 * FinInvoiceLink (multipart): source, number, date, amount, currency and the file; the kind and event for a document
 * that was not requested (checked by the Action).
 */
class DocumentAttachRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return [
            ...T01AttachDocument::rules(),
            'kind' => ['nullable', 'string', 'max:20'], 'event' => ['nullable', 'string', 'max:12'],
            'payment_id' => ['nullable', 'integer'], 'refund_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file'],
        ];
    }

    public function document(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }
}

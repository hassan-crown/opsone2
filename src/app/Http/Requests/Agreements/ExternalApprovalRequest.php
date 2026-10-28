<?php

namespace App\Http\Requests\Agreements;

use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use Illuminate\Http\UploadedFile;

/**
 * Multipart: channel, decided_on, note, the content hash the screen showed, and the evidence file (type and size are
 * checked by the attachment service).
 */
class ExternalApprovalRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return [...T02RecordExternalApproval::rules(), 'evidence' => ['required', 'file']];
    }

    public function evidence(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('evidence');
    }
}

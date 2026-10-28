<?php

namespace App\Http\Requests\Agreements;

use Illuminate\Http\UploadedFile;

class ContractRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return ['file' => ['nullable', 'file'], 'terms' => ['nullable', 'string', 'max:5000']];
    }

    public function upload(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }
}

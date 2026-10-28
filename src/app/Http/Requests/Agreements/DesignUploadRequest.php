<?php

namespace App\Http\Requests\Agreements;

use Illuminate\Http\UploadedFile;

class DesignUploadRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return [
            'file' => ['required', 'file'],
            'description' => ['required', 'string', 'max:2000'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*' => ['integer', 'distinct'],
            'replaces_id' => ['nullable', 'integer'],
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }
}

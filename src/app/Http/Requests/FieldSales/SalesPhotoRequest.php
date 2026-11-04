<?php

namespace App\Http\Requests\FieldSales;

use Illuminate\Foundation\Http\FormRequest;

/** A site photo; type and size are validated by AttachmentService. `client_uuid` is the device's id of this upload. */
class SalesPhotoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'file' => ['required', 'file'],
        ];
    }
}

<?php

namespace App\Http\Requests\Field;

use App\Models\Installation\InstallationPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A before/after photo; type and size are validated by AttachmentService (AllowedAttachment). `client_uuid` is the
 * device's id of this upload so a retry is recognised.
 */
class PhotoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phase' => ['required', Rule::in(InstallationPhoto::PHASES)],
            'client_uuid' => ['required', 'uuid'],
            'file' => ['required', 'file'],
        ];
    }
}

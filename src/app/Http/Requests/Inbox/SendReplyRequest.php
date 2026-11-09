<?php

namespace App\Http\Requests\Inbox;

use App\Inbox\InboxSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a reply; authorization (P-108), the 24-hour window and the template list are enforced by ReplySender.
 */
class SendReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:text,template'],
            'key' => ['required', 'string', 'uuid'],
            'body' => ['required_if:kind,text', 'nullable', 'string', 'max:4096'],
            'template' => ['required_if:kind,template', 'nullable', 'string', 'max:250'],
            'language' => ['required_if:kind,template', 'nullable', 'string', 'max:8'],
            'params' => ['nullable', 'array', 'max:'.InboxSettings::MAX_PARAMS],
            'params.*' => ['nullable', 'string', 'max:1024'],
        ];
    }
}

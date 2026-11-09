<?php

namespace App\Http\Requests\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Inbox\InboxSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The owner's WhatsApp inbox settings (AI-3, P-02): on/off, default branch for unknown contacts, approved templates.
 */
class UpdateWhatsappInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P02);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'default_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'templates' => ['nullable', 'array', 'max:'.InboxSettings::MAX_TEMPLATES],
            'templates.*.name' => ['required', 'string', 'regex:/^[a-z0-9_]{1,250}$/'],
            'templates.*.language' => ['required', 'string', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'templates.*.params' => ['required', 'integer', 'min:0', 'max:'.InboxSettings::MAX_PARAMS],
            'templates.*.label' => ['nullable', 'string', 'max:100'],
        ];
    }
}

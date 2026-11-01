<?php

namespace App\Http\Requests\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Whatsapp\WhatsAppEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhatsappRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P02);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'events' => ['present', 'array'],
            'events.*' => ['string', Rule::in(WhatsAppEvent::values())],
        ];
    }
}

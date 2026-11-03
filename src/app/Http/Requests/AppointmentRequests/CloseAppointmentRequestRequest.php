<?php

namespace App\Http\Requests\AppointmentRequests;

use App\Enums\AppointmentRequestCloseReason;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Close a customer appointment request with a reason (ADR-0039); P-54 over the request is checked in the action.
 */
class CloseAppointmentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'close_reason' => ['required', 'in:'.implode(',', AppointmentRequestCloseReason::values())],
            'close_note' => ['nullable', 'string', 'max:1000'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

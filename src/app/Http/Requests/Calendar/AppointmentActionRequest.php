<?php

namespace App\Http\Requests\Calendar;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of the single-purpose appointment actions (confirm, approve change, discard, cancel, complete).
 */
class AppointmentActionRequest extends FormRequest
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
            'lock_version' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'conflict_reason' => ['nullable', 'string', 'max:1000'],
            'confirmation_method' => ['nullable', 'in:'.implode(',', Appointment::CONFIRMATION_METHODS)],
            'outcome' => ['nullable', 'string', 'max:1000'],
            'no_show' => ['nullable', 'boolean'],
        ];
    }
}

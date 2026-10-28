<?php

namespace App\Http\Requests\Calendar;

use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            ...T00ProposeAppointment::rules(),
            'order_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'confirm_mode' => ['required', 'in:propose,send,confirm'],
            'confirmation_method' => ['required_if:confirm_mode,confirm', 'nullable', 'in:'.implode(',', Appointment::CONFIRMATION_METHODS)],
            'conflict_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

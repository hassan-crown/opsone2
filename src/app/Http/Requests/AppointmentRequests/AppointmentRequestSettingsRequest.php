<?php

namespace App\Http\Requests\AppointmentRequests;

use App\Actions\AppointmentRequests\UpdateAppointmentRequestSettings;
use Illuminate\Foundation\Http\FormRequest;

class AppointmentRequestSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...UpdateAppointmentRequestSettings::rules(), 'lock_version' => ['nullable', 'integer']];
    }
}

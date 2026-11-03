<?php

namespace App\Http\Requests\AppointmentRequests;

use App\AppointmentRequests\AppointmentRequestForm;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The workspace «request an appointment» form (ADR-0039); the customer comes from the `customer` guard only.
 */
class PortalAppointmentRequestRequest extends FormRequest
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
            ...AppointmentRequestForm::rules(AppointmentRequestSettings::current(), AppointmentRequest::SOURCE_PORTAL),
            'client_key' => ['required', 'uuid'],
        ];
    }
}

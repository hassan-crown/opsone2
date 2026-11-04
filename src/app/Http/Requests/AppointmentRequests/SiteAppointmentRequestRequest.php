<?php

namespace App\Http\Requests\AppointmentRequests;

use App\AppointmentRequests\AppointmentRequestForm;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The public «request an appointment» form (ADR-0039): the owner's field settings, plus the same spam guards as the
 * contact form (submission key, form-age token, honeypot).
 */
class SiteAppointmentRequestRequest extends FormRequest
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
            ...AppointmentRequestForm::rules(AppointmentRequestSettings::current(), AppointmentRequest::SOURCE_SITE),
            'submission' => ['required', 'uuid'],
            'form_token' => ['required', 'string'],
            'website' => ['nullable', 'string', 'max:255'], // honeypot
        ];
    }
}

<?php

namespace App\Http\Requests\AppointmentRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign a customer appointment request (ADR-0039); P-54 over the request is checked in the action.
 */
class AssignAppointmentRequestRequest extends FormRequest
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
            'assignee_id' => ['required', 'integer'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

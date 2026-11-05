<?php

namespace App\Http\Requests\EInvoicing;

use App\EInvoicing\ZatcaAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting onboarding: the 6-digit OTP the owner generated on the Fatoora portal (valid one hour, used once).
 */
class OnboardZatcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ZatcaAccess::manages($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['otp' => ['required', 'string', 'regex:/^\d{6}$/']];
    }
}

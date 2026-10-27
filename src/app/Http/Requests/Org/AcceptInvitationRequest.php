<?php

namespace App\Http\Requests\Org;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Public request: possession of the link and the emailed ownership-proof code are the credentials.
 */
class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

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
            'code' => ['required', 'string', 'digits:6'],
            'name' => ['required', 'string', 'max:120'],
            'password' => $this->passwordRules(),
        ];
    }
}

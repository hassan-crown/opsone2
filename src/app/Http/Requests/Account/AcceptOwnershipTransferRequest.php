<?php

namespace App\Http\Requests\Account;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepting ownership asks for the recipient's password again: a stolen session alone must not become the owner.
 * The action checks that the actor is the recipient.
 */
class AcceptOwnershipTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'current_password']];
    }
}

<?php

namespace App\Http\Requests\Account;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestOwnershipTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P07);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', Rule::exists('memberships', 'id')->where('status', MembershipStatus::Active->value)],
            'current_password' => ['required', 'string', 'current_password'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

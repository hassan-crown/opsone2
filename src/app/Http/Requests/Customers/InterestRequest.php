<?php

namespace App\Http\Requests\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Customers\Phone;
use App\Customers\Rules\PhoneNumber;
use App\Enums\InterestChannel;
use App\Models\Interest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record (P-09) or edit an interest. A mobile or an email is enough to follow the lead up; the conversion
 * to a customer later requires the mobile (Q-27).
 */
class InterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $interest = $this->route('interest');

        return $interest instanceof Interest
            ? Access::can($this->user(), Permission::P09, $interest)
            : Access::can($this->user(), Permission::P09);
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->input('mobile');
        $email = $this->input('email');
        $this->merge([
            'mobile' => is_string($mobile) && trim($mobile) !== '' ? (Phone::normalize($mobile) ?? trim($mobile)) : null,
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = ! $this->route('interest') instanceof Interest;

        return [
            'name' => ['required', 'string', 'max:160'],
            'mobile' => ['nullable', 'required_without:email', 'string', new PhoneNumber],
            'email' => ['nullable', 'required_without:mobile', 'string', 'email:rfc', 'max:190'],
            'need' => ['required', 'string', 'max:2000'],
            'channel' => ['required', Rule::enum(InterestChannel::class)],
            'branch_id' => $creating ? ['required', 'integer', Rule::exists('branches', 'id')->where('status', 'active')] : ['prohibited'],
            'customer_id' => $creating ? ['nullable', 'integer', Rule::exists('customers', 'id')] : ['prohibited'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('memberships', 'user_id')->where('status', 'active')],
        ];
    }
}

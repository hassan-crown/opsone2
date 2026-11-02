<?php

namespace App\Http\Requests\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Customers\CustomerData;
use App\Customers\Rules\PhoneNumber;
use App\Customers\Rules\SaudiCommercialRegistration;
use App\Customers\Rules\SaudiVatNumber;
use App\Enums\ContactChannel;
use App\Enums\CustomerKind;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (P-09) and edit (P-10) a customer. Input is normalized first, so uniqueness compares canonical values.
 * Email is required only when customer-workspace login is enabled (Q-27, ADR-0009).
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            ? Access::can($this->user(), Permission::P10, $customer)
            : Access::can($this->user(), Permission::P09);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomerData::normalize($this->only([
            'kind', 'mobile', 'email', 'office_phone', 'commercial_registration', 'vat_number', 'internal_notes', 'preferred_channel', 'national_address',
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customer = $this->route('customer');
        $ignore = $customer instanceof Customer ? $customer->id : null;
        $organization = $this->input('kind') === CustomerKind::Organization->value;

        return [
            'kind' => ['required', Rule::enum(CustomerKind::class)],
            'name' => ['required', 'string', 'max:160'],
            'mobile' => ['required', 'string', new PhoneNumber, Rule::unique('customers', 'mobile')->ignore($ignore)],
            'portal_login_enabled' => ['boolean'],
            'email' => [
                Rule::requiredIf($this->boolean('portal_login_enabled')), 'nullable', 'string', 'email:rfc', 'max:190',
                Rule::unique('customers', 'email')->ignore($ignore),
            ],
            'country' => ['sometimes', Rule::in(['SA'])],
            'branch_id' => $customer instanceof Customer
                ? ['prohibited']
                : ['required', 'integer', Rule::exists('branches', 'id')->where('status', 'active')],
            'assignee_id' => ['nullable', 'integer', Rule::exists('memberships', 'user_id')->where('status', 'active')],
            'preferred_channel' => ['nullable', Rule::enum(ContactChannel::class)],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'commercial_registration' => $organization ? ['nullable', new SaudiCommercialRegistration] : ['prohibited'],
            'vat_number' => $organization ? ['nullable', new SaudiVatNumber] : ['prohibited'],
            'office_phone' => $organization ? ['nullable', new PhoneNumber(false)] : ['prohibited'],
            // Saudi national address, needed for standard (B2B) tax invoices (ADR-0033).
            'national_address' => $organization ? ['nullable', 'array:'.implode(',', CustomerData::ADDRESS_PARTS)] : ['prohibited'],
            'national_address.street' => ['nullable', 'string', 'max:120'],
            'national_address.building_number' => ['nullable', 'regex:/^\d{4}$/'],
            'national_address.additional_number' => ['nullable', 'regex:/^\d{4}$/'],
            'national_address.district' => ['nullable', 'string', 'max:120'],
            'national_address.city' => ['nullable', 'string', 'max:80'],
            'national_address.postal_code' => ['nullable', 'regex:/^\d{5}$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerData(): array
    {
        $data = $this->validated();
        $data['portal_login_enabled'] = (bool) ($data['portal_login_enabled'] ?? false);

        return $data;
    }
}

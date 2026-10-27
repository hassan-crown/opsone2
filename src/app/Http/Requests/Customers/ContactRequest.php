<?php

namespace App\Http\Requests\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Customers\Phone;
use App\Customers\Rules\PhoneNumber;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or edit a contact person (P-10 over the customer). Contacts' mobiles are not subject to account uniqueness.
 */
class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = $this->route('contact');
        $customer = $contact instanceof CustomerContact ? $contact->customer : $this->route('customer');

        return $customer instanceof Customer && Access::can($this->user(), Permission::P10, $customer);
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->input('mobile');
        $email = $this->input('email');
        $this->merge([
            'mobile' => is_string($mobile) ? (Phone::normalize($mobile) ?? trim($mobile)) : $mobile,
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'mobile' => ['required', 'string', new PhoneNumber],
            'job_title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_primary' => ['boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\EInvoicing;

use App\Customers\Rules\SaudiCommercialRegistration;
use App\Customers\Rules\SaudiVatNumber;
use App\EInvoicing\Zatca\Api\ZatcaClients;
use App\EInvoicing\ZatcaAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The seller's tax profile (ADR-0032). Lengths keep each QR value within one TLV length byte (Arabic is 2 bytes a
 * letter); the address follows the Saudi national address (4-digit building, 5-digit postal code).
 */
class UpdateZatcaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ZatcaAccess::manages($this->user());
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['seller_name_ar', 'seller_name_en', 'vat_number', 'commercial_registration', 'street', 'building_number', 'additional_number', 'district', 'city', 'postal_code', 'branch_name', 'industry'] as $key) {
            if ($this->has($key)) {
                $value = is_string($this->input($key)) ? trim($this->input($key)) : $this->input($key);
                $trimmed[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer'],
            'enabled' => ['required', 'boolean'],
            'environment' => ['required', Rule::in(ZatcaClients::allowed())],
            'seller_name_ar' => ['nullable', 'string', 'max:120'],
            'seller_name_en' => ['nullable', 'string', 'max:120'],
            'vat_number' => ['nullable', new SaudiVatNumber],
            'commercial_registration' => ['nullable', new SaudiCommercialRegistration],
            'street' => ['nullable', 'string', 'max:120'],
            'building_number' => ['nullable', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'regex:/^\d{4}$/'],
            'district' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'regex:/^\d{5}$/'],
            'branch_name' => ['nullable', 'string', 'max:60'],
            'industry' => ['nullable', 'string', 'max:60'],
        ];
    }
}

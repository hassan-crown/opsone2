<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Models\Procurement\Supplier;
use App\Support\Translatable\Translated;
use Closure;

/**
 * Create / edit a supplier (P-45). Authorization is the Action's, on the record, through the central Gate.
 */
class SupplierRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('vat_number'))) {
            $this->merge(['vat_number' => preg_replace('/\s+/', '', $this->input('vat_number'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        $id = $this->route('supplier');

        return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:160'],
            'name.en' => ['nullable', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'vat_number' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! Supplier::validVatNumber((string) $value)) {
                    $fail(__('suppliers.error.vat_format'));
                }
            }, 'unique:suppliers,vat_number'.($id instanceof Supplier ? ','.$id->id : '')],
            'supplies' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierData(): array
    {
        $text = fn (string $key) => $this->filled($key) ? trim((string) $this->validated($key)) : null;

        return [
            'name' => Translated::fromArray($this->validated('name'))->toArray(),
            'contact_name' => $text('contact_name'), 'phone' => $text('phone'), 'email' => $text('email'),
            'vat_number' => $text('vat_number'), 'supplies' => $text('supplies'), 'notes' => $text('notes'),
        ];
    }
}

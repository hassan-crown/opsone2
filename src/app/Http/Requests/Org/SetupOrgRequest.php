<?php

namespace App\Http\Requests\Org;

use App\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetupOrgRequest extends FormRequest
{
    use Concerns;

    /** Currencies offered at setup (ISO 4217); the facility locks it after the first financial movement. */
    public const CURRENCIES = ['SAR', 'AED', 'KWD', 'BHD', 'QAR', 'OMR', 'EGP', 'USD', 'EUR'];

    public function authorize(): bool
    {
        return $this->allows(Permission::P02);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:160'],
            'name_en' => ['nullable', 'string', 'max:160'],
            'default_locale' => ['required', Rule::in(['ar', 'en'])],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'timezone' => ['required', 'timezone:all'],
        ];
    }
}

<?php

namespace App\Http\Requests\Account;

use App\Access\Access;
use App\Access\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacilityProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P02);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'string', 'email:strict', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{6,32}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'default_locale' => ['required', Rule::in(config('app.available_locales'))],
            'timezone' => ['required', 'string', Rule::in(config('account.timezones'))],
            'currency' => ['required', 'string', Rule::in(config('account.currencies'))],
            'version' => ['required', 'string'],
        ];
    }
}

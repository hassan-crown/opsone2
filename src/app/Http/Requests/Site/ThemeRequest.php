<?php

namespace App\Http\Requests\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Site\Theme\ThemeSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(Authorizer::class)->can($this->user(), Permission::P100);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'brand_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['sometimes', Rule::in(array_keys(ThemeSettings::FONTS))],
            'appearance' => ['sometimes', Rule::in(ThemeSettings::APPEARANCES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['brand_color.regex' => __('theme.error.invalid_color')];
    }
}

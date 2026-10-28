<?php

namespace App\Http\Requests\Agreements;

use App\Actions\Settings\UpdateFinanceSettings;
use Illuminate\Foundation\Http\FormRequest;

class FinanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...UpdateFinanceSettings::rules(), 'lock_version' => ['nullable', 'integer', 'min:1']];
    }
}

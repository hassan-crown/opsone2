<?php

namespace App\Http\Requests\Account;

use App\Actions\Settings\UpdateWorkSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkSettingsRequest extends FormRequest
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
        return UpdateWorkSettings::rules();
    }
}

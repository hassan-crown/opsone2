<?php

namespace App\Http\Requests\Returns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The return window in days (0–365); P-02 is checked in the Action.
 */
class ReturnSettingsRequest extends FormRequest
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
        return ['window_days' => ['required', 'integer', 'min:0', 'max:365']];
    }
}

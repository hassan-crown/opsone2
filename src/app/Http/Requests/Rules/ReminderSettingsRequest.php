<?php

namespace App\Http\Requests\Rules;

use App\Actions\Rules\UpdateReminderSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The collection reminder policy (ADR-0037). Authorization is the action's (P-104).
 */
class ReminderSettingsRequest extends FormRequest
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
        return [...UpdateReminderSettings::rules(), 'lock_version' => ['nullable', 'integer']];
    }
}

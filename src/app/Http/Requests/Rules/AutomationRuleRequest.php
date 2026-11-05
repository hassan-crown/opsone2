<?php

namespace App\Http\Requests\Rules;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An automation rule as the builder sends it (ADR-0042). The shape is validated in depth by AutomationRules::normalize
 * (closed triggers, fields, operators, actions); authorization is the action's (P-104).
 */
class AutomationRuleRequest extends FormRequest
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
        return [
            'key' => ['required', 'string', 'max:40'],
            'name' => ['required', 'array'],
            'trigger' => ['required', 'string', 'max:48'],
            'params' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['required', 'array'],
            'enabled' => ['required', 'boolean'],
            'position' => ['nullable', 'integer', 'between:0,999'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

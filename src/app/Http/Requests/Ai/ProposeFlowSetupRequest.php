<?php

namespace App\Http\Requests\Ai;

use App\Actions\Rules\RulesAccess;
use App\Workflow\FlowTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A setup proposal request (AI-1, P-104): the owner's description, an optional base template and the specialist's
 * answers to the previous proposal's questions.
 */
class ProposeFlowSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && RulesAccess::canEdit($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:20', 'max:6000'],
            'base_template' => ['nullable', 'string', Rule::in(FlowTemplates::keys())],
            'answers' => ['nullable', 'array', 'max:10'],
            'answers.*.question' => ['required', 'string', 'max:500'],
            'answers.*.answer' => ['required', 'string', 'max:1000'],
        ];
    }
}

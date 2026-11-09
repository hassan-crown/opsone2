<?php

namespace App\Http\Requests\Ai;

use App\Actions\Rules\RulesAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «اعتمد كمسودة» (AI-1, P-104): which parts to keep (the flow always; the plan and each rule by choice) and the flow's
 * lock version the specialist reviewed (409 when the flow changed meanwhile).
 */
class ApplyFlowSetupRequest extends FormRequest
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
            'include_plan' => ['required', 'boolean'],
            'rules' => ['present', 'array', 'max:10'],
            'rules.*' => ['string', 'max:60'],
            'lock_version' => ['required', 'integer', 'min:1'],
            // Required as true when the flow already has an unpublished draft (checked in ApplyFlowSetup).
            'replace_draft' => ['sometimes', 'boolean'],
        ];
    }
}

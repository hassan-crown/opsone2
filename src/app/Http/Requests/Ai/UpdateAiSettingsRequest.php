<?php

namespace App\Http\Requests\Ai;

use App\Access\Access;
use App\Access\Permission;
use App\Ai\AiFeature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
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
        $rules = ['features' => ['required', 'array:'.implode(',', AiFeature::values())]];
        foreach (AiFeature::values() as $key) {
            $rules["features.{$key}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}

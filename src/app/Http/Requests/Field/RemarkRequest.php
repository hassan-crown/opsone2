<?php

namespace App\Http\Requests\Field;

use App\Models\Installation\InstallationRemark;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemarkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(InstallationRemark::KINDS)],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'blocking' => ['required', 'boolean'],
        ];
    }
}

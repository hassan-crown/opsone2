<?php

namespace App\Http\Requests\Field;

use Illuminate\Foundation\Http\FormRequest;

class ResolveRemarkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'min:3', 'max:2000'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\Field;

use Illuminate\Foundation\Http\FormRequest;

class FinishRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}

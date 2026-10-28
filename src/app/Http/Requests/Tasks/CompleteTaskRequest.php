<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M-07-T05 completion with an optional result note. Authorization happens on the task inside the Action (central Gate).
 */
class CompleteTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'result_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function lockVersion(): ?int
    {
        return $this->filled('lock_version') ? (int) $this->input('lock_version') : null;
    }
}

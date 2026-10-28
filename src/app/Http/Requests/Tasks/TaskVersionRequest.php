<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Claim, start, resolve: only the version the screen read. Authorization happens on the task inside the Action (central Gate).
 */
class TaskVersionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function lockVersion(): ?int
    {
        return $this->filled('lock_version') ? (int) $this->input('lock_version') : null;
    }
}

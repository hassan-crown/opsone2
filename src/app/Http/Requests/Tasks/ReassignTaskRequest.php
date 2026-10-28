<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M-07-T06 (P-23): reassign with a reason. Authorization happens on the task inside the Action (central Gate).
 */
class ReassignTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'assignee_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function lockVersion(): ?int
    {
        return $this->filled('lock_version') ? (int) $this->input('lock_version') : null;
    }
}

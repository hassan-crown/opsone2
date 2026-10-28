<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M-07-T01 (P-22): a member or the department inbox. Authorization happens on the task inside the Action (central Gate).
 */
class AssignTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'assignee_id' => ['nullable', 'integer', 'required_without:department_id'],
            'department_id' => ['nullable', 'integer', 'required_without:assignee_id'],
        ];
    }

    public function lockVersion(): ?int
    {
        return $this->filled('lock_version') ? (int) $this->input('lock_version') : null;
    }
}

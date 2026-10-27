<?php

namespace App\Http\Requests\Org;

use App\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteStaffRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        return $this->allows(Permission::P05);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')],
            'from' => ['nullable', Rule::in(['wizard'])],
            ...$this->roleRules(),
        ];
    }

    /**
     * @return array{email: string, name: string, job_title: string|null, department_id: int|null, branch_ids: list<int>, roles: list<array{role_template_id: int, scope: string|null}>}
     */
    public function invitation(): array
    {
        return [
            'email' => (string) $this->validated('email'),
            'name' => (string) $this->validated('name'),
            'job_title' => $this->validated('job_title'),
            'department_id' => $this->validated('department_id') === null ? null : (int) $this->validated('department_id'),
            'branch_ids' => array_values(array_map(intval(...), $this->validated('branch_ids') ?? [])),
            'roles' => $this->roleRows(),
        ];
    }
}

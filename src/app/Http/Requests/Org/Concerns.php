<?php

namespace App\Http\Requests\Org;

use App\Access\Access;
use App\Access\Permission;
use Illuminate\Validation\Rule;

/**
 * Shared bits of the organization Form Requests.
 */
trait Concerns
{
    protected function allows(Permission $permission, ?object $record = null): bool
    {
        return Access::can($this->user(), $permission, $record);
    }

    /**
     * @return array<string, mixed>
     */
    protected function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*.role_template_id' => ['required', 'integer', Rule::exists('role_templates', 'id')->where('is_owner', false)],
            'roles.*.scope' => ['nullable', Rule::in(['tenant', 'region', 'branches', 'department'])],
        ];
    }

    /**
     * @return list<array{role_template_id: int, scope: string|null}>
     */
    protected function roleRows(): array
    {
        return array_values(array_map(fn (array $row) => [
            'role_template_id' => (int) $row['role_template_id'],
            'scope' => $row['scope'] ?? null,
        ], $this->validated('roles')));
    }
}

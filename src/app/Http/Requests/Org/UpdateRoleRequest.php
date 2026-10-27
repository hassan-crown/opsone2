<?php

namespace App\Http\Requests\Org;

use App\Access\Enums\Scope;
use App\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        return $this->allows(Permission::P04);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grants' => ['present', 'array'],
            'grants.*.permission' => ['required', 'string', Rule::in(array_map(fn (Permission $p) => $p->value, Permission::cases()))],
            'grants.*.scope' => ['required', Rule::in(array_map(fn (Scope $s) => $s->value, Scope::cases()))],
        ];
    }

    /**
     * @return list<array{permission: string, scope: string}>
     */
    public function grants(): array
    {
        return array_values(array_map(fn (array $g) => ['permission' => $g['permission'], 'scope' => $g['scope']], $this->validated('grants')));
    }
}

<?php

namespace App\Http\Requests\Org;

use App\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberAccessRequest extends FormRequest
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
        return $this->roleRules();
    }

    /**
     * @return list<array{role_template_id: int, scope: string|null}>
     */
    public function roles(): array
    {
        return $this->roleRows();
    }
}

<?php

namespace App\Http\Requests\Org;

use App\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;

class DisableMemberRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        return $this->allows(Permission::P06);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}

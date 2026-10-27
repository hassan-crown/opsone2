<?php

namespace App\Http\Requests\Org;

use App\Access\Permission;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        return $this->allows(Permission::P03);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $branch = $this->route('branch');

        return [
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('branches', 'code')->ignore($branch instanceof Branch ? $branch->id : null)],
            'region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'from' => ['nullable', Rule::in(['wizard'])],
        ];
    }
}

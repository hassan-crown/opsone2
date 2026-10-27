<?php

namespace App\Http\Requests\SupportAccess;

use App\Access\Access;
use App\Access\Permission;
use App\SupportAccess\SupportArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Owner's decision to open a requested support grant (P-79). The transition re-checks the areas and hours against
 * what was requested and what the decider holds.
 */
class GrantSupportAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P79);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'areas' => ['required', 'array', 'min:1'],
            'areas.*' => ['string', 'distinct', Rule::in(SupportArea::values())],
            // Opening facility data to OpsOne re-verifies the owner's password (as ownership transfer does).
            'current_password' => ['required', 'string', 'current_password'],
            'hours' => ['required', 'integer', 'min:1', 'max:'.(int) config('opsone.support.max_access_hours', 72)],
        ];
    }
}

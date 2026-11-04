<?php

namespace App\Http\Requests\FieldSales;

use App\FieldSales\FieldRepAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A supervisor turns one of a rep's field permissions on or off (ADR-0041). */
class RepPermissionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission' => ['required', Rule::in(FieldRepAccess::TOGGLES)],
            'enabled' => ['required', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Help;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Membership;
use App\Models\SupportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Anyone with an active staff membership may ask OpsOne for help (the screen is open to all roles).
 */
class StoreSupportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P84)
            && Membership::query()->where('user_id', $this->user()?->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(SupportRequest::CATEGORIES)],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'page_path' => ['nullable', 'string', 'max:300'],
        ];
    }
}

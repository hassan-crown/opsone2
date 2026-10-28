<?php

namespace App\Http\Requests\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Interest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reopen (T04) a closed interest: a reason and optionally another owner.
 */
class InterestReopenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $interest = $this->route('interest');

        return $interest instanceof Interest && Access::can($this->user(), Permission::P09, $interest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('memberships', 'user_id')->where('status', 'active')],
        ];
    }
}

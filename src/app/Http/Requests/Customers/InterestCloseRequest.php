<?php

namespace App\Http\Requests\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\InterestCloseReason;
use App\Models\Interest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Close (T03) an open interest with a reason; the note is required when the reason is «other».
 */
class InterestCloseRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(InterestCloseReason::class)],
            'note' => [Rule::requiredIf($this->input('reason') === InterestCloseReason::Other->value), 'nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P-17 edit (authorized on the record by App\Actions\Orders\UpdateOrder). `lock_version` is the version the client
 * read; a stale one gets 409.
 */
class UpdateOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'need_summary' => ['sometimes', 'required', 'string', 'max:2000'],
            'needs_measurement' => ['sometimes', 'boolean'],
            'needs_design' => ['sometimes', 'boolean'],
            'invoice_type' => ['sometimes', 'nullable', Rule::in(Order::INVOICE_TYPES)],
            'assignee_id' => ['sometimes', 'required', 'integer'],
            'contact_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        /** @var array<string, mixed> */
        return collect($this->validated())->except('lock_version')->all();
    }
}

<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply a branch transfer (orders: P-19, customers: P-10 — both checked on the record in the action). The reason is
 * required (Q-29); orders also carry their `lock_version`.
 */
class BranchTransferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'assignee_id' => ['nullable', 'integer'],
            'lock_version' => $this->route('order') instanceof Order ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ];
    }
}

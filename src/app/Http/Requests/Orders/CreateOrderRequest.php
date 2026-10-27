<?php

namespace App\Http\Requests\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\OrderLineType;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a custom order, a ready draft, or both (P-15). The idempotency key comes from the form field or the
 * `Idempotency-Key` header; the client generates one per create screen and reuses it on retries.
 */
class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P15);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'customer_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'purchase_reference_id' => ['nullable', 'integer'],

            'custom' => ['nullable', 'array', 'required_without:ready'],
            'custom.need_summary' => ['required_with:custom', 'string', 'max:2000'],
            'custom.needs_measurement' => ['nullable', 'boolean'],
            'custom.needs_design' => ['nullable', 'boolean'],
            'custom.invoice_type' => ['nullable', Rule::in(Order::INVOICE_TYPES)],
            'custom.lines' => ['nullable', 'array', 'max:50'],
            ...OrderLineRules::rules('custom.lines.*.', [OrderLineType::CustomProduct, OrderLineType::CustomNeed]),

            'ready' => ['nullable', 'array', 'required_without:custom'],
            'ready.invoice_type' => ['nullable', Rule::in(Order::INVOICE_TYPES)],
            'ready.lines' => ['required_with:ready', 'array', 'min:1', 'max:100'],
            ...OrderLineRules::rules('ready.lines.*.', [OrderLineType::ReadyVariant]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        /** @var array<string, mixed> */
        return collect($this->validated())->except('idempotency_key')->all();
    }
}

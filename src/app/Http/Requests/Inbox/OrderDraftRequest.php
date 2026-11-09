<?php

namespace App\Http\Requests\Inbox;

use App\Enums\OrderLineType;
use App\Http\Requests\Orders\OrderLineRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The reviewed lines of a conversation order draft (AI-5), for the price preview and for «أنشئ الطلب». Money is never
 * an input: amounts come from the catalog on the server. Sizes are centimetres with one decimal (1 cm – 30 m); the
 * line shape is the create screen's (OrderLineRules), checked again against the catalog by OrderLineBuilder.
 * Authorization (opening the conversation, owning the draft, P-15) is the controller's and the Action's.
 */
class OrderDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
        $size = ['nullable', 'numeric', 'decimal:0,1', 'min:1', 'max:3000'];

        return [
            'idempotency_key' => [$this->routeIs('inbox.order-draft.confirm') ? 'required' : 'nullable', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'branch_id' => ['required', 'integer'],
            'need_summary' => ['nullable', 'string', 'max:2000'],
            'needs_measurement' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.key' => ['nullable', 'string', 'max:40'],
            ...OrderLineRules::rules('lines.*.', [OrderLineType::CustomProduct, OrderLineType::CustomNeed, OrderLineType::ReadyVariant]),
            'lines.*.width_cm' => $size,
            'lines.*.height_cm' => $size,
            'lines.*.depth_cm' => $size,
            'lines.*.color' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function draftData(): array
    {
        /** @var array<string, mixed> */
        return $this->validated();
    }
}

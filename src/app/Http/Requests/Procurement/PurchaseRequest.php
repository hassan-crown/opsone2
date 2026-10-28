<?php

namespace App\Http\Requests\Procurement;

use App\Catalog\MinorUnits;
use App\Http\Requests\Agreements\AgreementWriteRequest;
use App\Models\Procurement\PurchaseOrderLine;
use App\Procurement\Quantity;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Create / edit a draft purchase order (P-46). Quantities arrive as decimal text, costs as decimal money text.
 */
class PurchaseRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;

    /** Creating takes a key (a double click must not make two POs); an edit is guarded by the draft's lock_version. */
    protected function prepareForValidation(): void
    {
        $this->requiresKey = $this->isMethod('POST');
        parent::prepareForValidation();
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'expected_on' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.item_type' => ['required', Rule::in([PurchaseOrderLine::MATERIAL, PurchaseOrderLine::READY_VARIANT])],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', function (string $attribute, mixed $value, Closure $fail) {
                if (! Quantity::isValidInput(is_numeric($value) ? (string) $value : $value) || Quantity::fromInput(trim((string) $value)) < 1) {
                    $fail(__('procurement.error.quantity'));
                }
            }],
            'lines.*.unit_cost' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && ! MinorUnits::isValidInput(is_numeric($value) ? (string) $value : $value)) {
                    $fail(__('catalog.error_amount'));
                }
            }],
            'lines.*.order_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseData(): array
    {
        $body = $this->body();
        $lines = [];
        foreach ((array) $body['lines'] as $line) {
            $cost = $line['unit_cost'] ?? null;
            $lines[] = [
                'id' => isset($line['id']) ? (int) $line['id'] : null,
                'item_type' => (string) $line['item_type'], 'item_id' => (int) $line['item_id'],
                'quantity_milli' => Quantity::fromInput(trim((string) $line['quantity'])),
                'unit_cost_minor' => $cost === null || $cost === '' ? null : MinorUnits::fromInput((string) $cost),
                'order_id' => isset($line['order_id']) && $line['order_id'] !== '' ? (int) $line['order_id'] : null,
            ];
        }

        return [
            'supplier_id' => (int) $body['supplier_id'], 'branch_id' => (int) $body['branch_id'],
            'location_id' => isset($body['location_id']) ? (int) $body['location_id'] : null,
            'expected_on' => $body['expected_on'] ?? null,
            'responsible_id' => isset($body['responsible_id']) ? (int) $body['responsible_id'] : null,
            'notes' => isset($body['notes']) && trim((string) $body['notes']) !== '' ? trim((string) $body['notes']) : null,
            'lines' => $lines,
        ];
    }
}

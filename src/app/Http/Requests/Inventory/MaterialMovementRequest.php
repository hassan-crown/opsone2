<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class MaterialMovementRequest extends FormRequest
{
    use QuantityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $issue = $this->routeIs('orders.materials.issue');

        return [
            'location_id' => ['required', 'integer'],
            'material_id' => ['required', 'integer'],
            'quantity' => $this->quantityRule(),
            'reservation_id' => ['nullable', 'integer'],
            'idempotency_key' => [$issue ? 'required' : 'nullable', 'string', 'max:80'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }

    public function quantityMilli(): int
    {
        return $this->milli('quantity');
    }
}

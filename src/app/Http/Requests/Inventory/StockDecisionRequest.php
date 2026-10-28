<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A transition on an existing stock record or order: optional version, reason or note, and a location on confirm.
 */
class StockDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:500'],
            'location_id' => ['nullable', 'integer'],
        ];
    }

    public function version(): ?int
    {
        return $this->filled('lock_version') ? $this->integer('lock_version') : null;
    }
}

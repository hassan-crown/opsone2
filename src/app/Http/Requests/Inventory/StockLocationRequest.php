<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\StockLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'code' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('stock_locations', 'code')->ignore($location instanceof StockLocation ? $location->id : null)],
            'name.ar' => ['nullable', 'string', 'max:120', 'required_without:name.en'],
            'name.en' => ['nullable', 'string', 'max:120', 'required_without:name.ar'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(StockLocation::STATUSES)],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array{code: string, name: array{ar: string|null, en: string|null}, branch_id: int|null, status: string, manager_id: int|null}
     */
    public function locationData(): array
    {
        $name = (array) $this->input('name', []);

        return [
            'code' => (string) $this->validated('code'),
            'name' => ['ar' => self::text($name['ar'] ?? null), 'en' => self::text($name['en'] ?? null)],
            'branch_id' => $this->filled('branch_id') ? $this->integer('branch_id') : null,
            'status' => (string) ($this->validated('status') ?? 'active'),
            'manager_id' => $this->filled('manager_id') ? $this->integer('manager_id') : null,
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

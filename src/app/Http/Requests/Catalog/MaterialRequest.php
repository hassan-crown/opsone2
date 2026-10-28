<?php

namespace App\Http\Requests\Catalog;

use App\Access\Permission;
use App\Catalog\Availability;
use App\Models\Catalog\CatalogMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        $material = $this->route('material');

        return $this->allows(Permission::P13, $material instanceof CatalogMaterial ? $material : new CatalogMaterial);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'material_group' => ['nullable', 'string', 'max:80'],
            ...$this->textRules('name', true, 160),
            ...$this->textRules('description', false, 2000),
            'unit' => ['required', 'string', 'max:24'],
            'availability' => ['required', Rule::enum(Availability::class)],
            'supplier_name' => ['nullable', 'string', 'max:120'],
            'track_quantity' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogData(): array
    {
        return [
            'material_group' => $this->filled('material_group') ? trim((string) $this->validated('material_group')) : null,
            'name' => $this->textValue('name'),
            'description' => $this->textValue('description'),
            'unit' => trim((string) $this->validated('unit')),
            'availability' => $this->validated('availability'),
            'supplier_name' => $this->filled('supplier_name') ? trim((string) $this->validated('supplier_name')) : null,
            'track_quantity' => (bool) $this->validated('track_quantity'),
        ];
    }
}

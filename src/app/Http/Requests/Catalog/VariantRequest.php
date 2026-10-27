<?php

namespace App\Http\Requests\Catalog;

use App\Access\Permission;
use App\Catalog\MinorUnits;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VariantRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        $product = $this->route('ready');
        $variant = $this->route('variant');

        return $this->allows(Permission::P12, $variant instanceof CatalogReadyVariant ? $variant : new CatalogReadyVariant)
            && $product instanceof CatalogReadyProduct;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $variant = $this->route('variant');

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('catalog_ready_variants', 'code')->ignore($variant instanceof CatalogReadyVariant ? $variant->id : null)],
            ...$this->textRules('name', false, 120),
            'price' => $this->amountRules(true),
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{code: string, name: array<string, mixed>|null, price_minor: int, is_default: bool}
     */
    public function catalogData(): array
    {
        return [
            'code' => (string) $this->validated('code'),
            'name' => $this->textValue('name'),
            'price_minor' => MinorUnits::fromInput((string) $this->validated('price')),
            'is_default' => (bool) $this->validated('is_default'),
        ];
    }
}

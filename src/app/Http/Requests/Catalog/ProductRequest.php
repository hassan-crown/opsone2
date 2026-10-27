<?php

namespace App\Http\Requests\Catalog;

use App\Access\Permission;
use App\Catalog\MinorUnits;
use App\Catalog\PricingBasis;
use App\Catalog\ProductOptionGroups;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $this->allows(Permission::P11, $product instanceof CatalogProduct ? $product : new CatalogProduct);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:80'],
            ...$this->textRules('name', true, 160),
            ...$this->textRules('description', false, 2000),
            'pricing_basis' => ['required', Rule::enum(PricingBasis::class)],
            'reference_price' => $this->amountRules(false),
            'option_groups' => ['nullable', 'array', 'max:30'],
            'option_groups.*.key' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'option_groups.*.label' => ['required', 'array'],
            'option_groups.*.label.ar' => ['required', 'string', 'max:120'],
            'option_groups.*.label.en' => ['nullable', 'string', 'max:120'],
            'option_groups.*.type' => ['required', Rule::in(ProductOptionGroups::TYPES)],
            'option_groups.*.required' => ['nullable', 'boolean'],
            'option_groups.*.unit' => ['nullable', 'string', 'max:24', 'required_if:option_groups.*.type,number'],
            'option_groups.*.values' => ['nullable', 'array', 'max:50'],
            'option_groups.*.values.*.ar' => ['nullable', 'string', 'max:120'],
            'option_groups.*.values.*.en' => ['nullable', 'string', 'max:120'],
            'option_groups.*.material_ids' => ['nullable', 'array', 'max:100'],
            'option_groups.*.material_ids.*' => ['integer', Rule::exists('catalog_materials', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $groups = (array) $this->input('option_groups', []);

            foreach ($groups as $i => $group) {
                if (! is_array($group) || ! in_array($group['type'] ?? null, ['single', 'multiple'], true)) {
                    continue;
                }
                $values = array_filter((array) ($group['values'] ?? []), fn ($x) => is_array($x) && (trim((string) ($x['ar'] ?? '')) !== '' || trim((string) ($x['en'] ?? '')) !== ''));
                if ($values === [] && (array) ($group['material_ids'] ?? []) === []) {
                    $v->errors()->add("option_groups.$i.values", __('catalog.product.error_choices'));
                }
            }

            // A disabled material cannot be added to a group; one already in the product's groups may stay.
            $product = $this->route('product');
            $existing = $product instanceof CatalogProduct ? ProductOptionGroups::materialIds($product->option_groups) : [];
            $requested = array_map(intval(...), array_merge([], ...array_map(fn ($g) => is_array($g) ? (array) ($g['material_ids'] ?? []) : [], $groups)));
            $blocked = CatalogMaterial::query()->whereIn('id', array_diff($requested, $existing))->whereNot('status', 'active')->exists();
            if ($blocked) {
                $v->errors()->add('option_groups', __('catalog.product.error_disabled_material'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogData(): array
    {
        $price = $this->validated('reference_price');
        /** @var list<array<string, mixed>> $groups */
        $groups = array_values((array) ($this->validated('option_groups') ?? []));

        return [
            'category' => $this->filled('category') ? trim((string) $this->validated('category')) : null,
            'name' => $this->textValue('name'),
            'description' => $this->textValue('description'),
            'pricing_basis' => $this->validated('pricing_basis'),
            'reference_price_minor' => $price === null || $price === '' ? null : MinorUnits::fromInput((string) $price),
            'option_groups' => ProductOptionGroups::normalize($groups),
        ];
    }
}

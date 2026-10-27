<?php

namespace App\Http\Requests\Catalog;

use App\Access\Permission;
use App\Models\Catalog\CatalogReadyProduct;
use Illuminate\Foundation\Http\FormRequest;

class ReadyProductRequest extends FormRequest
{
    use Concerns;

    public function authorize(): bool
    {
        $product = $this->route('ready');

        return $this->allows(Permission::P12, $product instanceof CatalogReadyProduct ? $product : new CatalogReadyProduct);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->textRules('name', true, 160),
            ...$this->textRules('description', false, 2000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogData(): array
    {
        return ['name' => $this->textValue('name'), 'description' => $this->textValue('description')];
    }
}

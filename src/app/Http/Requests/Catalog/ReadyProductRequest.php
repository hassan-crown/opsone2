<?php

namespace App\Http\Requests\Catalog;

use App\Access\Permission;
use App\Models\Catalog\CatalogReadyProduct;
use App\Site\Seo\Slug;
use App\Support\Translatable\Translated;
use Closure;
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
            // Search fields (ADR-0045): all optional, smart defaults apply on the public page.
            'slug' => ['nullable', 'array'],
            'slug.ar' => ['nullable', 'string', 'max:'.Slug::MAX, $this->slugRule()],
            'slug.en' => ['nullable', 'string', 'max:'.Slug::MAX, $this->slugRule()],
            ...$this->textRules('seo_title', false, 120),
            ...$this->textRules('seo_description', false, 320),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogData(): array
    {
        $name = Translated::fromArray($this->validated('name'));
        $slug = Translated::fromArray($this->validated('slug'));

        return [
            'name' => $this->textValue('name'), 'description' => $this->textValue('description'),
            // An empty slug is made from the name of that language (Q-78: Arabic for the Arabic URL).
            'slug' => [
                'ar' => Slug::make($slug->only('ar') ?: $name->only('ar')) ?: null,
                'en' => Slug::make($slug->only('en') ?: $name->only('en')) ?: null,
            ],
            'seo_title' => $this->textValue('seo_title'),
            'seo_description' => $this->textValue('seo_description'),
        ];
    }

    private function slugRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ! Slug::isValid($value)) {
                $fail(__('catalog.ready.slug_invalid'));
            }
        };
    }
}

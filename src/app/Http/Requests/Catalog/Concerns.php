<?php

namespace App\Http\Requests\Catalog;

use App\Access\Access;
use App\Access\Permission;
use App\Catalog\MinorUnits;
use App\Support\Translatable\Translated;
use Closure;

/**
 * Shared bits of the catalog Form Requests.
 */
trait Concerns
{
    protected function allows(Permission $permission, ?object $record = null): bool
    {
        return Access::can($this->user(), $permission, $record);
    }

    /**
     * Bilingual text rules: the Arabic text is required (the default language); English is optional and the
     * reader falls back to the other language.
     *
     * @return array<string, mixed>
     */
    protected function textRules(string $field, bool $required, int $max): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'array'],
            $field.'.ar' => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            $field.'.en' => ['nullable', 'string', 'max:'.$max],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function textValue(string $field): ?array
    {
        $value = Translated::fromArray($this->validated($field));

        return $value->isEmpty() ? null : $value->toArray();
    }

    /**
     * A money input rule: decimal text with at most two decimals, in minor units after parsing.
     *
     * @return list<mixed>
     */
    protected function amountRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && ! MinorUnits::isValidInput(is_numeric($value) ? (string) $value : $value)) {
                    $fail(__('catalog.error_amount'));
                }
            },
        ];
    }
}

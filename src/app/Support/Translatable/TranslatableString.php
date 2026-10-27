<?php

namespace App\Support\Translatable;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for a bilingual JSON column `{"ar": "...", "en": "..."}` (Q-28, ADR-0009):
 *
 *     protected function casts(): array { return ['name' => TranslatableString::class]; }
 *
 *     $product->name->get('en');   // falls back to the other locale when empty
 *     $product->name = ['ar' => 'مطبخ', 'en' => 'Kitchen'];   // or a Translated instance
 *
 * @implements CastsAttributes<Translated, mixed>
 */
final class TranslatableString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Translated
    {
        if (! is_string($value) || $value === '') {
            return new Translated;
        }

        $decoded = json_decode($value, true);

        return Translated::fromArray(is_array($decoded) ? $decoded : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            throw new InvalidArgumentException("[$key] expects a Translated value or an array with ar/en keys.");
        }

        $translated = $value instanceof Translated ? $value : Translated::fromArray(is_array($value) ? $value : null);

        return $translated->isEmpty() ? null : json_encode($translated->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

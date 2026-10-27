<?php

namespace App\Catalog;

use App\Support\Translatable\Translated;
use Illuminate\Support\Str;

/**
 * Option groups of a made-to-order product (COM-04): groups the facility names, with a fixed set of kinds — no
 * logic editor and no programmable operating fields. Kept as plain JSON on the product (and in its versions).
 */
final class ProductOptionGroups
{
    public const TYPES = ['single', 'multiple', 'text', 'number', 'attachment'];

    /**
     * @param  list<array<string, mixed>>  $groups  already validated by the Form Request
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $type = (string) $group['type'];
            $choice = $type === 'single' || $type === 'multiple';

            $out[] = [
                'key' => (string) ($group['key'] ?? '') !== '' ? (string) $group['key'] : Str::lower(Str::random(8)),
                'label' => Translated::fromArray($group['label'] ?? null)->toArray(),
                'type' => $type,
                'required' => (bool) ($group['required'] ?? false),
                'unit' => $type === 'number' ? self::text($group['unit'] ?? null) : null,
                'values' => $choice ? array_values(array_map(
                    fn (array $v) => Translated::fromArray($v)->toArray(),
                    array_filter($group['values'] ?? [], fn ($v) => is_array($v) && ! Translated::fromArray($v)->isEmpty()),
                )) : [],
                'material_ids' => $choice ? array_values(array_unique(array_map(intval(...), $group['material_ids'] ?? []))) : [],
            ];
        }

        return $out;
    }

    /**
     * Material ids referenced by any group.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<int>
     */
    public static function materialIds(array $groups): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (array $g) => array_map(intval(...), $g['material_ids'] ?? []), $groups ?: [[]]))));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

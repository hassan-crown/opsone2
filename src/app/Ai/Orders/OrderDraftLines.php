<?php

namespace App\Ai\Orders;

use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;

/**
 * Turns the model's extracted items into draft lines the server stands behind (AI-5). The model only reads; this class
 * decides what is kept:
 *
 * - Catalog item: only through OrderDraftCatalog's label map, and only if still selectable. Anything else is a line
 *   «needs a manual choice» with no item — never the nearest match, never auto-filled.
 * - Dimensions: kept only with an explicit unit the customer used (cm / mm / m), converted here to whole millimetres,
 *   and only inside a plausible range (1 cm – 30 m). A size without a unit, or out of range, is dropped with a warning:
 *   «سم أم م» is never guessed.
 * - Quantity: positive, at most three decimals; a ready item needs a whole number of pieces. A quantity in metres or
 *   square metres must match how the product is priced, otherwise it is dropped with a warning.
 * - No price, discount or total is read from the model: the schema has no such field, and prices are computed by
 *   OrderDraftPricing from the catalog when the draft is shown and when it is confirmed.
 */
final class OrderDraftLines
{
    public const MIN_MM = 10;

    public const MAX_MM = 30_000;

    public const UNITS = ['piece', 'meter', 'square_meter'];

    public const DIMENSION_UNITS = ['cm', 'mm', 'm'];

    /**
     * @param  list<array<string, mixed>>  $items  validated model output
     * @param  array<string, int>  $messages  message label → message id
     * @return list<array<string, mixed>>
     */
    public static function fromModel(array $items, OrderDraftCatalog $catalog, array $messages): array
    {
        $lines = [];
        foreach (array_values($items) as $i => $item) {
            $warnings = [];
            $resolved = $catalog->resolve(is_string($item['ref'] ?? null) ? $item['ref'] : null);
            $type = $resolved['type'] ?? null;
            $id = $resolved['id'] ?? null;
            if ($type !== null && ! self::selectable($type, (int) $id)) {
                [$type, $id] = [null, null];
            }
            if ($type === null) {
                $warnings[] = 'needs_choice';
            }

            [$dims, $dimWarnings] = self::dimensions($item);
            $warnings = [...$warnings, ...$dimWarnings];

            $unit = in_array($item['quantity_unit'] ?? null, self::UNITS, true) ? (string) $item['quantity_unit'] : null;
            $quantity = self::quantity($item['quantity'] ?? null);
            if (($item['quantity'] ?? null) !== null && $quantity === null) {
                $warnings[] = 'quantity_invalid';
            }
            if ($type === 'ready_variant') {
                if ($unit !== null && $unit !== 'piece') {
                    $warnings[] = 'unit_mismatch';
                    [$quantity, $unit] = [null, null];
                } elseif ($quantity !== null && ! ctype_digit($quantity)) {
                    $warnings[] = 'whole_quantity';
                    $quantity = null;
                }
            }
            if ($type === 'custom_product' && $unit !== null && $unit !== 'piece') {
                $basis = CatalogProduct::query()->find($id)?->pricing_basis->value;
                if (OrderDraftPricing::unitFor($basis) !== $unit) {
                    $warnings[] = 'unit_mismatch';
                    [$quantity, $unit] = [null, null];
                }
            }

            $source = is_string($item['source'] ?? null) ? ($messages[strtolower(trim($item['source']))] ?? null) : null;

            $lines[] = [
                'key' => 'l'.($i + 1),
                'type' => $type,
                'item_id' => $id,
                'description' => mb_substr(trim((string) ($item['description'] ?? '')), 0, 300),
                'quantity' => $quantity,
                'unit' => $unit,
                ...$dims,
                'color' => self::text($item['color'] ?? null, 60),
                'source_message_id' => $source,
                'confidence' => in_array($item['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $item['confidence'] : 'low',
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        return $lines;
    }

    /**
     * A positive decimal with at most three places, as a plain string; null when absent or not acceptable.
     */
    public static function quantity(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }
        $text = is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : trim((string) $value);
        if (preg_match('/^\d{1,6}(\.\d{1,3})?$/', $text) !== 1 || bccomp($text, '0', 3) <= 0 || bccomp($text, '999999', 3) > 0) {
            return null;
        }

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : (ltrim($text, '0') ?: '0');
    }

    /**
     * Centimetres as the staff member types them (`250`, `12.5`) to whole millimetres, or null when not a size.
     */
    public static function cmToMm(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match('/^\d{1,4}(\.\d)?$/', $text) !== 1) {
            return null;
        }
        $mm = (int) bcmul($text, '10', 0);

        return $mm >= self::MIN_MM && $mm <= self::MAX_MM ? $mm : null;
    }

    public static function mmToCm(?int $mm): ?string
    {
        if ($mm === null) {
            return null;
        }

        return $mm % 10 === 0 ? (string) intdiv($mm, 10) : intdiv($mm, 10).'.'.($mm % 10);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: array{width_mm: int|null, height_mm: int|null, depth_mm: int|null}, 1: list<string>}
     */
    private static function dimensions(array $item): array
    {
        $dims = ['width_mm' => null, 'height_mm' => null, 'depth_mm' => null];
        $given = array_filter(['width_mm' => $item['width'] ?? null, 'height_mm' => $item['height'] ?? null, 'depth_mm' => $item['depth'] ?? null], fn ($v) => $v !== null);
        if ($given === []) {
            return [$dims, []];
        }

        $unit = $item['dimension_unit'] ?? null;
        if (! in_array($unit, self::DIMENSION_UNITS, true)) {
            return [$dims, ['dimension_unit_missing']];
        }

        $factor = ['mm' => '1', 'cm' => '10', 'm' => '1000'][$unit];
        $warnings = [];
        foreach ($given as $key => $value) {
            if (! is_int($value) && ! is_float($value)) {
                $warnings[] = 'dimension_out_of_range';

                continue;
            }
            $mm = (int) round(((float) $value) * (int) $factor);
            if ($mm < self::MIN_MM || $mm > self::MAX_MM) {
                $warnings[] = 'dimension_out_of_range';

                continue;
            }
            $dims[$key] = $mm;
        }

        return [$dims, $warnings];
    }

    private static function selectable(string $type, int $id): bool
    {
        return match ($type) {
            'custom_product' => (bool) CatalogProduct::query()->find($id)?->isSelectable(),
            'ready_variant' => (bool) CatalogReadyVariant::query()->with('product')->find($id)?->isSelectable(),
            default => false,
        };
    }

    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}

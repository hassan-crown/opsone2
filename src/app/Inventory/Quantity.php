<?php

namespace App\Inventory;

use InvalidArgumentException;

/**
 * Quantities as integers in thousandths of the item's unit (Q-43 a). Text a person types (`2`, `1.5`, `0.125`) is
 * converted with string arithmetic only; a float never carries a quantity.
 */
final class Quantity
{
    public const SCALE = 1000;

    /** Largest quantity accepted (one billion units). */
    public const MAX_MILLI = 1_000_000_000_000;

    public static function parse(string|int $value): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,3}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("Not a quantity: {$text}");
        }
        $milli = (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '', 3, '0');
        if ($milli > self::MAX_MILLI) {
            throw new InvalidArgumentException("Quantity too large: {$text}");
        }

        return $milli;
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }
        try {
            return self::parse($value) > 0;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * `1500` → `1.5`, `2000` → `2`, `125` → `0.125`.
     */
    public static function format(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);
        $whole = intdiv($milli, self::SCALE);
        $fraction = rtrim(str_pad((string) ($milli % self::SCALE), 3, '0', STR_PAD_LEFT), '0');

        return $sign.$whole.($fraction === '' ? '' : '.'.$fraction);
    }
}

<?php

namespace App\Procurement;

/**
 * Quantities are integers in thousandths of the item's fixed unit (Q-43: no unit conversion, no floats). This parses
 * the decimal text a person types (`2.5`, `10`, `0.125`) with string arithmetic only.
 */
final class Quantity
{
    public const MAX_MILLI = 999_999_999_999;

    public static function isValidInput(mixed $text): bool
    {
        return is_string($text) && preg_match('/^\d{1,9}(\.\d{1,3})?$/', trim($text)) === 1;
    }

    public static function fromInput(string $text): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($text), 2), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public static function toInput(int $milli): string
    {
        $whole = intdiv($milli, 1000);
        $fraction = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole.'.'.$fraction;
    }
}

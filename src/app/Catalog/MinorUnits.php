<?php

namespace App\Catalog;

use InvalidArgumentException;

/**
 * Money is an integer count of minor units (halalas); floats never carry an amount. This converts the decimal
 * text a person types (`12.5`, `1200`, `0.05`) to minor units with string arithmetic only.
 */
final class MinorUnits
{
    public const MAX = 99_999_999_999;

    public static function isValidInput(mixed $text): bool
    {
        return is_string($text) && preg_match('/^\d{1,11}([.,]\d{1,2})?$/', trim($text)) === 1;
    }

    public static function fromInput(string $text): int
    {
        $text = str_replace(',', '.', trim($text));
        if (! self::isValidInput($text)) {
            throw new InvalidArgumentException('Not a valid amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * `12.50` for 1250; for display and form defaults only.
     */
    public static function toInput(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}

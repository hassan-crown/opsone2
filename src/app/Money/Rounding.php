<?php

namespace App\Money;

use App\Catalog\MinorUnits;
use InvalidArgumentException;

/**
 * The single rounding policy of OpsOne money (Q-34 a): amounts are integer halalas and every division or fractional
 * product is rounded half-up (half away from zero) once, per line, before any sum. Arithmetic runs on decimal strings
 * (bcmath), so no intermediate value is a float and no product overflows before the range check.
 */
final class Rounding
{
    /**
     * Half-up quotient of two integers given as decimal strings: 5/2 → 3, -5/2 → -3, 4/3 → 1.
     */
    public static function divide(string $numerator, string $denominator): int
    {
        $numerator = self::integer($numerator);
        $denominator = self::integer($denominator);
        if (bccomp($denominator, '0') === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $quotient = bcdiv($numerator, $denominator, 0); // truncates toward zero
        $remainder = bcsub($numerator, bcmul($quotient, $denominator));
        if (bccomp(bcmul(self::abs($remainder), '2'), self::abs($denominator)) >= 0) {
            $negative = (str_starts_with($numerator, '-')) !== (str_starts_with($denominator, '-'));
            $quotient = bcadd($quotient, $negative ? '-1' : '1');
        }

        return self::toInt($quotient);
    }

    /**
     * Half-up value of a decimal string with any number of places: `12.345` → 12, `0.5` → 1, `-0.5` → -1.
     */
    public static function decimal(string $value): int
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException('Not a decimal.');
        }
        $places = str_contains($value, '.') ? strlen(explode('.', $value, 2)[1]) : 0;
        $scale = bcpow('10', (string) $places);

        return self::divide(bcmul($value, $scale, 0), $scale);
    }

    /**
     * An integer string inside the stored range, as int.
     */
    public static function toInt(string $value): int
    {
        $value = self::integer($value);
        if (bccomp(self::abs($value), (string) MinorUnits::MAX) > 0) {
            throw MoneyOverflow::of($value);
        }

        return (int) $value;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function abs(string $value): string
    {
        /** @var numeric-string $abs */
        $abs = ltrim($value, '-');

        return $abs;
    }

    /**
     * @return numeric-string
     */
    private static function integer(string $value): string
    {
        if (preg_match('/^-?\d+$/', $value) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException('Not an integer.');
        }

        return $value;
    }
}

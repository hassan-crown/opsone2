<?php

namespace App\Finance\BankTransfer;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Saudi IBAN (ADR-0046): `SA`, two check digits, a two-digit bank code and 18 account characters (24 in all), with a
 * valid ISO 13616 mod-97 check. Spaces and lowercase are accepted on input; the stored value is `normalize()`d.
 */
final class SaudiIban implements ValidationRule
{
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $value));
    }

    public static function valid(string $value): bool
    {
        $iban = self::normalize($value);
        if (preg_match('/^SA\d{4}[A-Z0-9]{18}$/', $iban) !== 1) {
            return false;
        }

        // Move the first four characters to the end, letters → numbers (A=10 … Z=35), then mod 97 in chunks.
        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** «SA03 8000 0000 6080 1016 7519» — groups of four for display. */
    public static function grouped(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valid($value)) {
            $fail('bank.validation.iban')->translate();
        }
    }
}

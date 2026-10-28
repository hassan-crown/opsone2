<?php

namespace App\Customers;

/**
 * Normalizes phone numbers to E.164 so uniqueness is checked on one canonical form (COM-01).
 * Saudi numbers accept 05XXXXXXXX, 5XXXXXXXX, 9665XXXXXXXX, 009665XXXXXXXX and +9665XXXXXXXX
 * (Arabic-Indic digits and separators included); any other country must be written with `+` or `00`.
 */
final class Phone
{
    /**
     * @return string|null the E.164 number, or null when the input is not a valid number
     */
    public static function normalize(?string $raw, bool $mobileOnly = true): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = self::digits($raw);
        $international = str_starts_with(trim($raw), '+') || str_starts_with($digits, '00');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($digits === '') {
            return null;
        }

        if (! $international) {
            if (str_starts_with($digits, '966')) {
                $international = true;
            } else {
                $national = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;

                return self::saudi($national, $mobileOnly);
            }
        }

        if (str_starts_with($digits, '966')) {
            return self::saudi(substr($digits, 3), $mobileOnly);
        }

        // Other countries: E.164 allows 8 to 15 digits, first digit never 0.
        return preg_match('/^[1-9]\d{7,14}$/', $digits) === 1 ? '+'.$digits : null;
    }

    private static function saudi(string $national, bool $mobileOnly): ?string
    {
        $pattern = $mobileOnly ? '/^5\d{8}$/' : '/^[1-9]\d{8}$/';

        return preg_match($pattern, $national) === 1 ? '+966'.$national : null;
    }

    private static function digits(string $raw): string
    {
        $map = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

        return preg_replace('/\D+/', '', strtr($raw, $map)) ?? '';
    }
}

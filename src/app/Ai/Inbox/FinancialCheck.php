<?php

namespace App\Ai\Inbox;

use App\Ai\Contracts\OutputCheck;
use App\Ai\Redaction\Redactor;

/**
 * «No number without a source» for money and dates (plan §2 axis 2, §3.3 point 4), stricter than the general
 * OutputValidator: there a number only has to appear somewhere in the facts (an order number would do); here
 *
 * - an amount (a number next to ريال / ر.س / SAR / ﷼ / هللة) must equal a money fact (`*_halalas`, in riyals);
 * - a percentage (%, ٪, «بالمئة») must equal a percentage fact (`*_percent`) — there is none today, so no discount
 *   or percentage the facility did not state can reach a draft;
 * - a date (2026-11-20, 20/11, 20/11/2026) must equal a date fact (`*_on`, `*_date`, `*_at`).
 *
 * The customer's own messages are not sources: a price the customer typed is not a price the facility gave.
 */
final class FinancialCheck implements OutputCheck
{
    private const CURRENCY = '(?:ريال|ريالات|ر\s?\.\s?س|رس\b|SAR|SR\b|﷼|هللة|هلله|halalas?)';

    private const NUMBER = '\d{1,3}(?:[,٬]\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?';

    public function check(array $output, array $facts): array
    {
        $money = [];
        $percent = [];
        $dates = [];
        array_walk_recursive($facts, function (mixed $value, int|string $key) use (&$money, &$percent, &$dates): void {
            $key = (string) $key;
            if (str_ends_with($key, '_halalas') && is_numeric($value)) {
                $money[self::normalize((string) (((int) $value) / 100))] = true;
            } elseif (str_ends_with($key, '_percent') && is_numeric($value)) {
                $percent[self::normalize((string) $value)] = true;
            } elseif ((str_ends_with($key, '_on') || str_ends_with($key, '_date') || str_ends_with($key, '_at')) && is_string($value)) {
                $date = substr($value, 0, 10);
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1) {
                    $dates[$date] = true;
                    $dates[(int) $m[3].'/'.(int) $m[2]] = true;
                    $dates[(int) $m[3].'/'.(int) $m[2].'/'.$m[1]] = true;
                }
            }
        });

        $problems = [];
        array_walk_recursive($output, function (mixed $value, int|string $key) use ($money, $percent, $dates, &$problems): void {
            if (! is_string($value)) {
                return;
            }
            $path = '$.'.$key;
            $text = (string) preg_replace('/\[[A-Z][A-Z_]*_\d+\]/', ' ', Redactor::foldDigits($value));

            foreach (self::amounts($text) as $amount) {
                if (! isset($money[$amount])) {
                    $problems[] = "{$path}: amount without a money fact";
                }
            }
            foreach (self::percentages($text) as $p) {
                if (! isset($percent[$p])) {
                    $problems[] = "{$path}: percentage without a source";
                }
            }
            foreach (self::datesIn($text) as $date) {
                if (! isset($dates[$date])) {
                    $problems[] = "{$path}: date without a source";
                }
            }
        });

        return array_values(array_unique($problems));
    }

    /**
     * @return list<string>
     */
    private static function amounts(string $text): array
    {
        $n = self::NUMBER;
        $c = self::CURRENCY;
        preg_match_all("/(?<![\\d.\\/-])({$n})\\s*{$c}|{$c}\\s*({$n})(?![\\d\\/-])/iu", $text, $m);

        $out = [];
        foreach (array_keys($m[0]) as $i) {
            $raw = $m[1][$i] !== '' ? $m[1][$i] : $m[2][$i];
            $out[] = self::normalize($raw);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function percentages(string $text): array
    {
        $n = self::NUMBER;
        preg_match_all("/({$n})\\s*(?:%|٪|بالمئة|بالمائة|في\\s+المئة|في\\s+المائة|percent)/iu", $text, $m);

        return array_map(self::normalize(...), $m[1]);
    }

    /**
     * @return list<string> ISO dates as written, and d/m or d/m/yyyy without leading zeros
     */
    private static function datesIn(string $text): array
    {
        $out = [];
        preg_match_all('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $iso, PREG_SET_ORDER);
        foreach ($iso as $m) {
            $out[] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            $text = str_replace($m[0], ' ', $text);
        }
        preg_match_all('~\b(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?\b~', $text, $dm, PREG_SET_ORDER);
        foreach ($dm as $m) {
            $year = $m[3] ?? '';
            if (strlen($year) === 2) {
                $year = '20'.$year;
            }
            $out[] = (int) $m[1].'/'.(int) $m[2].($year !== '' ? '/'.$year : '');
        }

        return $out;
    }

    private static function normalize(string $raw): string
    {
        $plain = str_replace([',', '٬'], '', $raw);
        if (str_contains($plain, '.')) {
            $plain = rtrim(rtrim($plain, '0'), '.');
        }

        return ltrim($plain, '0') === '' ? '0' : ltrim($plain, '0');
    }
}

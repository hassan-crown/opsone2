<?php

namespace App\Ai\Sales;

/**
 * The qualification fields of a new lead (plan §5.1): product, district, rough budget, preferred measurement time,
 * and the contact details the customer gave. Lead (`interests`) has name, email and a `need` text, so the three
 * qualification answers are written into `need` as labelled lines. Everything is clipped and cleaned here: the model's
 * text is never trusted as-is, and staff may edit every value before accepting.
 */
final class LeadFields
{
    public const KEYS = ['name', 'email', 'product', 'district', 'budget', 'preferred_time'];

    private const MAX = ['name' => 120, 'email' => 190, 'product' => 300, 'district' => 120, 'budget' => 120, 'preferred_time' => 160];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string|null>
     */
    public static function clean(array $raw): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $value = $raw[$key] ?? null;
            $value = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '') : '';
            $value = mb_substr($value, 0, self::MAX[$key]);
            if ($key === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $value = '';
            }
            $out[$key] = $value === '' ? null : $value;
        }

        return $out;
    }

    /**
     * The `need` text of the lead from the qualification answers.
     *
     * @param  array<string, string|null>  $fields
     */
    public static function need(array $fields): ?string
    {
        $lines = [];
        foreach (['product' => 'المنتج', 'district' => 'الحي', 'budget' => 'الميزانية', 'preferred_time' => 'الموعد المفضل'] as $key => $label) {
            if (($fields[$key] ?? null) !== null) {
                $lines[] = $label.': '.$fields[$key];
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}

<?php

namespace App\FieldSales;

use App\Configuration\ConfigReleases;
use App\Models\FieldSales\FieldSalesSettings;

/**
 * The field-sales settings in force (ADR-0041): the `field_sales` section of the current configuration release
 * (ADR-0040); before a release carries it, the built-in defaults — field sales off.
 */
final class FieldSalesConfig
{
    /**
     * @return array{enabled: bool, kinds: list<string>, response_hours: int|null, max_discount_bp: int}
     */
    public static function current(): array
    {
        $section = ConfigReleases::current()['field_sales'] ?? null;

        return is_array($section) ? self::normalize($section) : self::defaults();
    }

    public static function enabled(): bool
    {
        return self::current()['enabled'];
    }

    /**
     * Appointment kinds reps handle in the field app (none while field sales is off).
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        $current = self::current();

        return $current['enabled'] ? $current['kinds'] : [];
    }

    /**
     * @return array{enabled: bool, kinds: list<string>, response_hours: int|null, max_discount_bp: int}
     */
    public static function defaults(): array
    {
        return self::normalize(FieldSalesSettings::DEFAULTS);
    }

    /**
     * The draft row as a snapshot section.
     *
     * @return array{enabled: bool, kinds: list<string>, response_hours: int|null, max_discount_bp: int}
     */
    public static function capture(FieldSalesSettings $settings): array
    {
        return self::normalize(['enabled' => $settings->enabled, 'kinds' => $settings->kinds, 'response_hours' => $settings->response_hours,
            'max_discount_bp' => $settings->max_discount_bp]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{enabled: bool, kinds: list<string>, response_hours: int|null, max_discount_bp: int}
     */
    private static function normalize(array $values): array
    {
        $kinds = array_values(array_filter(FieldSalesSettings::KINDS, fn (string $k) => in_array($k, (array) ($values['kinds'] ?? []), true)));

        return [
            'enabled' => (bool) ($values['enabled'] ?? false),
            'kinds' => $kinds,
            'response_hours' => isset($values['response_hours']) ? (int) $values['response_hours'] : null,
            'max_discount_bp' => (int) ($values['max_discount_bp'] ?? 0),
        ];
    }
}

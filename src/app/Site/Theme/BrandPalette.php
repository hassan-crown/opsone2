<?php

namespace App\Site\Theme;

/**
 * Server-side Alpha palette (THM-03, design-system-integration §6): derives the brand roles of the light, dark and
 * contrast themes from one facility colour and guards WCAG AA. A colour that is too light or too dark to be read is
 * either auto-adjusted (same hue, shifted lightness, `adjusted`) or refused (`rejected`, shift too large to keep the
 * identity). Neutrals, state colours and the highlight stay the design system's — only brand roles change.
 */
final class BrandPalette
{
    public const ALGORITHM = 1;

    public const DEFAULT_COLOR = '#0E7C72';

    /** Largest lightness shift (OKLCH L) accepted when darkening the colour for the light theme. */
    private const MAX_SHIFT = 0.30;

    /** design-system neutrals the pairs are checked against (tokens.json). */
    private const LIGHT = ['background' => '#FFFFFF', 'surface' => '#FFFFFF'];

    private const DARK = ['background' => '#08121F', 'surface' => '#0F1D31'];

    public const PRESETS = [
        'teal' => '#0E7C72', 'blue' => '#1D5FA8', 'indigo' => '#4B4FB5', 'plum' => '#8A3F8F',
        'crimson' => '#B3263E', 'orange' => '#B85A12', 'green' => '#2D7A3E', 'slate' => '#3F5A78',
    ];

    /**
     * @return array{status: string, input: string, brand: string, shift: float, palette: array<string, array<string, string>>|null, report: list<array{mode: string, pair: string, ratio: float, min: float, pass: bool}>, message: string|null}
     */
    public static function derive(string $hex): array
    {
        $hex = strtoupper($hex);
        if (! Color::valid($hex)) {
            return self::result('rejected', $hex, $hex, 0.0, null, [], 'theme.error.invalid_color');
        }

        [$L, $C, $H] = Color::toOklch($hex);
        $C = min($C, 0.19); // cap chroma so no derived colour turns neon

        // Light brand: darken until white text on it and it as text on white both reach 4.5.
        $lightL = $L;
        while ($lightL > 0.05 && Color::contrast('#FFFFFF', Color::fromOklch($lightL, $C, $H)) < 4.5) {
            $lightL -= 0.005;
        }
        $shift = round($L - $lightL, 3);
        if ($shift > self::MAX_SHIFT) {
            return self::result('rejected', $hex, $hex, $shift, null, [], 'theme.error.too_light');
        }
        $brand = Color::fromOklch($lightL, $C, $H);
        $palette = [
            'light' => self::light($lightL, $C, $H),
            'dark' => self::dark($C, $H),
            'contrast' => self::contrastMode($C, $H),
        ];
        $report = self::report($palette);
        if (array_filter($report, fn ($r) => ! $r['pass']) !== []) {
            return self::result('rejected', $hex, $hex, $shift, null, $report, 'theme.error.contrast');
        }

        $changed = $shift > 0.004 || Color::contrast('#FFFFFF', $hex) < 4.5;

        return self::result($changed ? 'adjusted' : 'ok', $hex, $palette['light']['brand'], $shift, $palette, $report, $changed ? 'theme.notice.adjusted' : null);
    }

    /**
     * Re-checks every pair of an already derived palette (also used before applying a stored theme).
     *
     * @param  array<string, array<string, string>>  $palette
     * @return list<array{mode: string, pair: string, ratio: float, min: float, pass: bool}>
     */
    public static function report(array $palette): array
    {
        $rows = [];
        $modes = [
            'light' => [self::LIGHT['background'], self::LIGHT['surface'], 4.5, 3.0],
            'dark' => [self::DARK['background'], self::DARK['surface'], 4.5, 3.0],
            'contrast' => [self::LIGHT['background'], self::LIGHT['surface'], 7.0, 3.0],
        ];
        foreach ($modes as $mode => [$bg, $surface, $text, $ui]) {
            $p = $palette[$mode];
            $pairs = [
                'on-brand/brand' => [$p['on-brand'], $p['brand'], $text],
                'brand/surface' => [$p['brand'], $surface, $text],
                'brand/background' => [$p['brand'], $bg, $text],
                'brand-hover/surface' => [$p['brand-hover'], $surface, $text],
                'brand-subtle-text/brand-subtle' => [$p['brand-subtle-text'], $p['brand-subtle'], $text],
                'focus/background' => [$p['focus'], $bg, $ui],
            ];
            foreach ($pairs as $name => [$fg, $back, $min]) {
                $ratio = round(Color::contrast($fg, $back), 2);
                $rows[] = ['mode' => $mode, 'pair' => $name, 'ratio' => $ratio, 'min' => $min, 'pass' => $ratio >= $min];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private static function light(float $L, float $C, float $H): array
    {
        $brand = Color::fromOklch($L, $C, $H);
        $hover = Color::fromOklch($L - 0.06, $C, $H);
        $subtle = Color::fromOklch(0.96, $C * 0.18, $H);
        $subtleText = self::readableOn($subtle, $L - 0.08, $C, $H, 4.5, true);

        return [
            'brand' => $brand, 'brand-hover' => $hover, 'brand-active' => Color::fromOklch($L - 0.12, $C, $H),
            'brand-subtle' => $subtle, 'brand-subtle-text' => $subtleText, 'on-brand' => '#FFFFFF',
            'focus' => $brand, 'focus-halo' => Color::fromOklch(0.86, $C * 0.5, $H),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function dark(float $C, float $H): array
    {
        $L = 0.5;
        while ($L < 0.95 && Color::contrast(Color::fromOklch($L, $C, $H), self::DARK['surface']) < 4.5) {
            $L += 0.005;
        }
        $L = max($L, 0.68);
        $brand = Color::fromOklch($L, $C, $H);
        $subtle = Color::fromOklch(0.27, $C * 0.35, $H);
        $onBrand = Color::fromOklch(0.2, $C * 0.4, $H);
        if (Color::contrast($onBrand, $brand) < 4.5) {
            $onBrand = self::DARK['background'];
        }

        return [
            'brand' => $brand, 'brand-hover' => Color::fromOklch(min(0.97, $L + 0.06), $C, $H),
            'brand-active' => Color::fromOklch(min(0.98, $L + 0.12), $C, $H),
            'brand-subtle' => $subtle, 'brand-subtle-text' => self::readableOn($subtle, min(0.97, $L + 0.06), $C, $H, 4.5, false),
            'on-brand' => $onBrand, 'focus' => $brand, 'focus-halo' => Color::fromOklch(0.35, $C * 0.5, $H),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function contrastMode(float $C, float $H): array
    {
        $L = 0.5;
        while ($L > 0.05 && Color::contrast('#FFFFFF', Color::fromOklch($L, $C, $H)) < 7.0) {
            $L -= 0.005;
        }
        $brand = Color::fromOklch($L, $C, $H);
        $subtle = Color::fromOklch(0.96, $C * 0.12, $H);

        return [
            'brand' => $brand, 'brand-hover' => Color::fromOklch(max(0.05, $L - 0.07), $C, $H),
            'brand-active' => Color::fromOklch(max(0.04, $L - 0.14), $C, $H),
            'brand-subtle' => $subtle, 'brand-subtle-text' => self::readableOn($subtle, $L - 0.05, $C, $H, 7.0, true),
            'on-brand' => '#FFFFFF', 'focus' => $brand, 'focus-halo' => Color::fromOklch(0.86, $C * 0.5, $H),
        ];
    }

    /**
     * The nearest lightness (from `$L`, toward dark when `$darken`) at which the colour reads on `$background`.
     */
    private static function readableOn(string $background, float $L, float $C, float $H, float $min, bool $darken): string
    {
        $color = Color::fromOklch($L, $C, $H);
        for ($i = 0; $i < 200 && Color::contrast($color, $background) < $min; $i++) {
            $L += $darken ? -0.005 : 0.005;
            $color = Color::fromOklch($L, $C, $H);
        }

        return $color;
    }

    /**
     * @param  array<string, array<string, string>>|null  $palette
     * @param  list<array{mode: string, pair: string, ratio: float, min: float, pass: bool}>  $report
     * @return array{status: string, input: string, brand: string, shift: float, palette: array<string, array<string, string>>|null, report: list<array{mode: string, pair: string, ratio: float, min: float, pass: bool}>, message: string|null}
     */
    private static function result(string $status, string $input, string $brand, float $shift, ?array $palette, array $report, ?string $message): array
    {
        if ($palette !== null && $report === []) {
            $report = self::report($palette);
        }

        return ['status' => $status, 'input' => $input, 'brand' => $brand, 'shift' => $shift, 'palette' => $palette, 'report' => $report, 'message' => $message];
    }
}

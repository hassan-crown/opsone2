<?php

namespace App\Site\Theme;

use App\Models\Site\SiteTheme;

/**
 * Value helpers around the theme JSON: the self-hosted font choices (THM-02), default appearance and the CSS the
 * public site injects. Only validated hex values and known keys ever reach the style block (THM-07).
 */
final class ThemeSettings
{
    /** key => CSS font stack (families are the self-hosted IBM Plex files). */
    public const FONTS = [
        'plex-arabic' => "'IBM Plex Sans Arabic', 'IBM Plex Sans', system-ui, sans-serif",
        'plex-sans' => "'IBM Plex Sans', 'IBM Plex Sans Arabic', system-ui, sans-serif",
    ];

    public const APPEARANCES = ['system', 'light', 'dark'];

    public const ASSET_KINDS = ['logo', 'logo_dark', 'favicon'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'brand_input' => null, 'brand' => null, 'palette' => null, 'font' => 'plex-arabic', 'appearance' => 'system',
            'logo' => null, 'logo_dark' => null, 'favicon' => null, 'algorithm' => BrandPalette::ALGORITHM,
        ];
    }

    /**
     * The live settings (default Alpha when nothing was applied).
     *
     * @return array<string, mixed>
     */
    public static function published(?SiteTheme $theme = null): array
    {
        $theme ??= SiteTheme::current();

        return array_merge(self::defaults(), $theme->published ?? []);
    }

    /**
     * The settings the editor works on: the draft when there is one, else the published.
     *
     * @return array<string, mixed>
     */
    public static function editing(SiteTheme $theme): array
    {
        return array_merge(self::defaults(), $theme->draft ?? $theme->published ?? []);
    }

    /**
     * A `<style>` body that overrides the brand roles on the site surface only. Empty for the default theme.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function css(array $settings): string
    {
        $css = '';
        $font = self::FONTS[$settings['font'] ?? ''] ?? null;
        if ($font !== null && ($settings['font'] ?? 'plex-arabic') !== 'plex-arabic') {
            $css .= "[data-surface='site']{--font-sans:{$font};font-family:{$font}}";
        }
        $palette = $settings['palette'] ?? null;
        if (! is_array($palette)) {
            return $css;
        }
        $selectors = [
            'light' => "[data-surface='site']",
            'dark' => ".dark [data-surface='site'],[data-theme='dark'] [data-surface='site']",
            'contrast' => "[data-theme='contrast'] [data-surface='site']",
        ];
        foreach ($selectors as $mode => $selector) {
            $vars = '';
            foreach ($palette[$mode] ?? [] as $role => $hex) {
                if (preg_match('/^[a-z-]+$/', (string) $role) === 1 && Color::valid((string) $hex)) {
                    $vars .= "--{$role}:{$hex};";
                }
            }
            if ($vars !== '') {
                $vars .= $mode === 'light' ? '--link:var(--brand);' : '';
                $css .= "{$selector}{{$vars}}";
            }
        }

        return $css;
    }
}

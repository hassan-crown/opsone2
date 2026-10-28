<?php

namespace App\Site\Theme;

/**
 * sRGB ⇄ OKLCH and WCAG 2.x contrast (no external library). Colours are `#RRGGBB` strings.
 */
final class Color
{
    public static function valid(string $hex): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /**
     * @return array{float, float, float} OKLCH (L 0..1, C, H degrees)
     */
    public static function toOklch(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $v) => self::lin($v / 255), self::rgb($hex));
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = [$l ** (1 / 3), $m ** (1 / 3), $s ** (1 / 3)];
        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$L, sqrt($a * $a + $bb * $bb), fmod(rad2deg(atan2($bb, $a)) + 360, 360)];
    }

    /**
     * Converts to a hex string, reducing chroma until the colour fits the sRGB gamut.
     */
    public static function fromOklch(float $L, float $C, float $H): string
    {
        $L = max(0.0, min(1.0, $L));
        for ($i = 0; $i < 40; $i++) {
            $rgb = self::oklchToLinear($L, $C, $H);
            if ($rgb[0] >= -0.0005 && $rgb[0] <= 1.0005 && $rgb[1] >= -0.0005 && $rgb[1] <= 1.0005 && $rgb[2] >= -0.0005 && $rgb[2] <= 1.0005) {
                break;
            }
            $C *= 0.92;
        }
        $out = '#';
        foreach (self::oklchToLinear($L, $C, $H) as $v) {
            $out .= sprintf('%02X', (int) round(max(0.0, min(1.0, self::gamma(max(0.0, min(1.0, $v))))) * 255));
        }

        return $out;
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(fn (int $v) => self::lin($v / 255), self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * @return array{float, float, float}
     */
    private static function oklchToLinear(float $L, float $C, float $H): array
    {
        $a = $C * cos(deg2rad($H));
        $b = $C * sin(deg2rad($H));
        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function lin(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function gamma(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }
}

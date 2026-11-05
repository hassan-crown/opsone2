<?php

namespace App\Site\Seo;

/**
 * Readable URL slugs in Arabic or English (Q-78): letters and digits of any script kept, everything else becomes one
 * hyphen, Latin lowercased, Arabic diacritics and tatweel dropped. `مقبض ألمنيوم 20 سم` → `مقبض-ألمنيوم-20-سم`.
 */
final class Slug
{
    public const MAX = 80;

    public static function make(?string $text): string
    {
        $text = (string) $text;
        // Harakat, Quranic marks and tatweel carry no meaning in a URL.
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $text);
        $text = trim($text, '-');

        return trim(mb_substr($text, 0, self::MAX, 'UTF-8'), '-');
    }

    /** Is the value already a clean slug (what an editor may type)? */
    public static function isValid(string $slug): bool
    {
        return $slug !== '' && $slug === self::make($slug);
    }
}

<?php

namespace App\Support;

/**
 * Key of the shared translations once-prop (ADR-0027): changes with the locale and with any edit of the language
 * files, so a deploy or a switch of language makes the client load the strings again.
 */
final class TranslationsVersion
{
    public static function key(string $prop): string
    {
        $stamp = '';
        foreach (array_unique([(string) config('app.fallback_locale'), app()->getLocale()]) as $locale) {
            $path = lang_path($locale.'.json');
            $stamp .= is_file($path) ? $locale.filemtime($path).'-'.filesize($path) : $locale;
        }

        return $prop.'.'.app()->getLocale().'.'.hash('xxh3', $stamp);
    }
}

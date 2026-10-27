import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import type en from '../../../lang/en.json';

export type Locale = 'ar' | 'en';
export type Direction = 'rtl' | 'ltr';
export type TranslationKey = keyof typeof en;
export type Replacements = Record<string, string | number>;
export type TranslateFn = (
    key: TranslationKey,
    replacements?: Replacements,
) => string;

export const directionOf = (locale: Locale): Direction =>
    locale === 'ar' ? 'rtl' : 'ltr';

/** Laravel-style `:name` placeholder replacement. */
export function translate(
    dictionary: Record<string, string>,
    key: TranslationKey,
    replacements: Replacements = {},
): string {
    const template = dictionary[key] ?? key;

    return Object.entries(replacements).reduce(
        (text, [name, value]) => text.replaceAll(`:${name}`, String(value)),
        template,
    );
}

export function useT(): TranslateFn {
    const { translations } = usePage().props;

    return useCallback(
        (key, replacements) => translate(translations, key, replacements),
        [translations],
    );
}

export function useLocale(): {
    locale: Locale;
    dir: Direction;
    locales: Locale[];
} {
    const { locale, dir, locales } = usePage().props;

    return { locale, dir, locales };
}

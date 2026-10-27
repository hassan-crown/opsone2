import type { Locale } from '@/lib/i18n';

/** Currency labels shown after (ar) / before (en) the number; single place to change later. */
export const CURRENCY_LABEL: Record<string, Record<Locale, string>> = {
    SAR: { ar: 'ر.س', en: 'SAR' },
};

/** Digits stay Latin in both languages (design-system rule). */
export function formatNumber(
    value: number,
    options?: Intl.NumberFormatOptions,
): string {
    return new Intl.NumberFormat('en-US', options).format(value);
}

export type AmountParts = { sign: string; number: string; label: string };

export function amountParts(
    value: number,
    locale: Locale,
    currency = 'SAR',
): AmountParts {
    return {
        sign: value < 0 ? '−' : '',
        number: formatNumber(Math.abs(value), {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }),
        label: CURRENCY_LABEL[currency]?.[locale] ?? currency,
    };
}

/** Plain-text form (exports, aria labels): number then currency in Arabic, currency then number in English. */
export function formatAmount(
    value: number,
    locale: Locale,
    currency = 'SAR',
): string {
    const { sign, number, label } = amountParts(value, locale, currency);

    return locale === 'ar'
        ? `${sign}${number} ${label}`
        : `${sign}${label} ${number}`;
}

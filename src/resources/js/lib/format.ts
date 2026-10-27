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

export function formatAmount(
    value: number,
    locale: Locale,
    currency = 'SAR',
): string {
    const number = formatNumber(Math.abs(value), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const label = CURRENCY_LABEL[currency]?.[locale] ?? currency;
    const sign = value < 0 ? '−' : '';

    return locale === 'ar'
        ? `${sign}${number} ${label}`
        : `${sign}${label} ${number}`;
}

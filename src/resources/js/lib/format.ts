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

export type DateStyle = 'short' | 'long';

/** Tables use `short` ("1 نوفمبر 2026"), detail pages `long` ("الأحد، 1 نوفمبر 2026"). */
const DATE_STYLES: Record<DateStyle, Intl.DateTimeFormatOptions> = {
    short: { day: 'numeric', month: 'long', year: 'numeric' },
    long: { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' },
};

const TIME: Intl.DateTimeFormatOptions = { hour: 'numeric', minute: '2-digit' };

/** Gregorian calendar and Latin digits in both languages (design-system rule). */
function dateLocale(locale: Locale): string {
    return `${locale === 'ar' ? 'ar-SA' : 'en'}-u-nu-latn-ca-gregory`;
}

/** A date-only value ("2026-11-01") is a calendar day, not UTC midnight: parse it as local so it never shifts a day. */
function toDate(value: string | Date): Date {
    if (value instanceof Date) {
        return value;
    }

    const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return day
        ? new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))
        : new Date(value);
}

/**
 * One date format for the whole app. Pass a style (`short` for tables, `long` for details) or,
 * for the rare special case, raw `Intl` options. An unparsable value is returned unchanged.
 */
export function formatDate(
    value: string | Date,
    locale: Locale,
    style: DateStyle | Intl.DateTimeFormatOptions = 'short',
): string {
    const date = toDate(value);

    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat(
        dateLocale(locale),
        typeof style === 'string' ? DATE_STYLES[style] : style,
    ).format(date);
}

/** Date and time ("1 نوفمبر 2026، 10:15 ص"). */
export function formatDateTime(
    value: string | Date,
    locale: Locale,
    style: DateStyle = 'short',
): string {
    return formatDate(value, locale, { ...DATE_STYLES[style], ...TIME });
}

/** Time of day only ("10:15 ص"). */
export function formatTime(value: string | Date, locale: Locale): string {
    return formatDate(value, locale, TIME);
}

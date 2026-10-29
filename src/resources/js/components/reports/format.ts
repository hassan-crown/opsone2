import { formatNumber } from '@/lib/format';
import type { Locale } from '@/lib/i18n';
import type { ColumnType } from '@/types/reports';

/** Integer-only scaling for display: `scale` is 100 (halalas) or 1000 (thousandths). No float arithmetic. */
function scaled(value: number, scale: number, digits: number, trim: boolean) {
    const negative = value < 0;
    const abs = Math.abs(Math.trunc(value));
    const whole = Math.trunc(abs / scale);
    const fraction = String(abs % scale).padStart(digits, '0');
    const kept = trim ? fraction.replace(/0+$/, '') : fraction;
    const text = formatNumber(whole) + (kept ? `.${kept}` : '');

    return (negative && abs !== 0 ? '−' : '') + text;
}

/** Halalas → "1,234.50" (the unit label is added by the caller). */
export function formatHalalas(value: number): string {
    return scaled(value, 100, 2, false);
}

/** Thousandths → "1,234.5" (up to 3 decimals, trailing zeros dropped). */
export function formatThousandths(value: number): string {
    return scaled(value, 1000, 3, true);
}

export function formatDecimal(value: number, digits = 1): string {
    return formatNumber(value, { maximumFractionDigits: digits });
}

export type CellFormatter = {
    /** Numbers/dates only; enum and null handling stay with the caller. */
    (value: number | string, type: ColumnType): string;
};

export function makeFormatter(
    _locale: Locale,
    moneyUnit: string,
): CellFormatter {
    return (value, type) => {
        const num = typeof value === 'number' ? value : Number(value);

        switch (type) {
            case 'money':
                return `${formatHalalas(num)} ${moneyUnit}`;
            case 'qty':
                return formatThousandths(num);
            case 'int':
                return formatNumber(num);
            case 'hours':
                return formatDecimal(num, 1);
            case 'percent':
                return `${formatDecimal(num, 1)}%`;
            // ISO text (Y-m-d, Y-m-d H:i): identical in both locales and never scrambled by bidi reordering.
            case 'date':
            case 'datetime':
                return String(value);
            default:
                return String(value);
        }
    };
}

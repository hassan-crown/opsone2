import { formatNumber } from '@/lib/format';

/** Integer thousandths shown as a decimal: up to 3 decimals, no trailing zeros, Latin digits. */
export function formatQty(milli: number): string {
    const sign = milli < 0 ? '−' : '';
    const abs = Math.abs(milli);
    const whole = Math.floor(abs / 1000);
    const fraction = String(abs % 1000)
        .padStart(3, '0')
        .replace(/0+$/, '');

    return `${sign}${formatNumber(whole)}${fraction ? `.${fraction}` : ''}`;
}

/** Plain decimal text for an input (no grouping). */
export function milliToInput(milli: number): string {
    const whole = Math.floor(milli / 1000);
    const fraction = String(milli % 1000)
        .padStart(3, '0')
        .replace(/0+$/, '');

    return `${whole}${fraction ? `.${fraction}` : ''}`;
}

/** Decimal text (up to 3 decimals) to integer thousandths; `null` when it is not a valid quantity. Empty is 0. */
export function parseQty(text: string): number | null {
    const value = text.trim();
    if (value === '') {
        return 0;
    }
    const match = /^(\d+)(?:\.(\d{1,3}))?$/.exec(value);
    if (!match) {
        return null;
    }

    return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
}

/** Halalas to the decimal text a cost input holds. */
export function minorToInput(minor: number): string {
    const whole = Math.floor(minor / 100);
    const cents = String(minor % 100).padStart(2, '0');

    return `${whole}.${cents}`;
}

/** Decimal money text to halalas; `null` when empty or invalid. */
export function parseMoney(text: string): number | null {
    const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(text.trim());
    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

import type { EditableLine, PricedContent } from './types';

/** `12.50` for 1250 — integer arithmetic only (money is never a float on the way to the server). */
export function minorToText(minor: number | null | undefined): string {
    if (minor === null || minor === undefined) {
        return '';
    }
    const sign = minor < 0 ? '-' : '';
    const abs = Math.abs(minor);

    return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** Basis points as a percentage text: 1250 → `12.5`, 1500 → `15`. */
export function bpToPercent(bp: number | null | undefined): string {
    if (bp === null || bp === undefined) {
        return '';
    }
    const whole = Math.trunc(bp / 100);
    const fraction = String(bp % 100)
        .padStart(2, '0')
        .replace(/0+$/, '');

    return fraction === '' ? String(whole) : `${whole}.${fraction}`;
}

/** Percentage text to basis points without floats: `12.5` → 1250. Null when not a valid 0–100 percentage. */
export function percentToBp(text: string): number | null {
    const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(text.trim());
    if (!match) {
        return null;
    }
    const bp = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));

    return bp <= 10000 ? bp : null;
}

/** For display only: the server sends integer halalas, `<Amount>` takes the major unit. */
export const toMajor = (minor: number): number => minor / 100;

let sequence = 0;

export function editableLines(content: PricedContent): EditableLine[] {
    return content.lines.map((line) => ({
        key: `l${++sequence}`,
        order_line_id: line.order_line_id,
        description: line.description,
        quantity: line.quantity,
        unit: line.unit ?? '',
        unit_price: minorToText(line.unit_price_minor),
        commissionable: line.commissionable,
    }));
}

export function newLine(): EditableLine {
    return {
        key: `l${++sequence}`,
        order_line_id: null,
        description: '',
        quantity: '1',
        unit: '',
        unit_price: '',
        commissionable: true,
    };
}

/** One idempotency key per screen action, reused on retry (docs/p6-finance-api.md). */
export function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `k-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

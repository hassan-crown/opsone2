import { formatNumber } from '@/lib/format';
import type { TranslateFn, TranslationKey } from '@/lib/i18n';

/** Units the UI translates. Mirrors `App\Inventory\StockUnit` plus the measuring units. */
export const UNIT_CODES = [
    'piece',
    'meter',
    'square_meter',
    'linear_meter',
    'sheet',
    'roll',
    'kilogram',
    'liter',
    'box',
    'set',
    'pair',
    'cm',
    'mm',
] as const;

export type UnitCode = (typeof UNIT_CODES)[number];

/**
 * Free-text labels a catalogue or order line may already carry, matched case-insensitively
 * (same list as `StockUnit::aliases()`, so "m", "unit" and "م" all land on a known unit).
 */
const ALIASES: Record<UnitCode, string[]> = {
    piece: [
        'piece',
        'pieces',
        'pcs',
        'pc',
        'unit',
        'units',
        'u',
        'قطعة',
        'حبة',
        'وحدة',
    ],
    meter: ['meter', 'meters', 'metre', 'm', 'متر', 'م'],
    square_meter: [
        'square_meter',
        'square meter',
        'sqm',
        'm2',
        'm²',
        'متر مربع',
        'م2',
        'م²',
    ],
    linear_meter: ['linear_meter', 'linear meter', 'lm', 'متر طولي', 'م.ط'],
    sheet: ['sheet', 'board', 'لوح', 'لوحة'],
    roll: ['roll', 'لفة', 'رول'],
    kilogram: ['kilogram', 'kg', 'كيلو', 'كيلوغرام', 'كجم'],
    liter: ['liter', 'litre', 'l', 'لتر'],
    box: ['box', 'carton', 'علبة', 'كرتون'],
    set: ['set', 'طقم'],
    pair: ['pair', 'زوج'],
    cm: ['cm', 'سم'],
    mm: ['mm', 'مم'],
};

const LOOKUP = new Map<string, UnitCode>(
    UNIT_CODES.flatMap((code) =>
        ALIASES[code].map((alias) => [alias, code] as const),
    ),
);

/** The known unit behind a free-text label, or null when the label is the facility's own word. */
export function unitCode(unit: string | null | undefined): UnitCode | null {
    return LOOKUP.get((unit ?? '').trim().toLowerCase()) ?? null;
}

/** Translated short unit ("م", "قطعة"); an unknown label is shown as the facility typed it. */
export function unitLabel(
    unit: string | null | undefined,
    t: TranslateFn,
): string {
    const code = unitCode(unit);

    return code ? t(`units.${code}` as TranslationKey) : (unit ?? '').trim();
}

export type QuantityParts = {
    /** Latin digits, no trailing zeros ("2.9", not "2.900"). Show it LTR-isolated. */
    number: string;
    unit: string;
};

export function quantityParts(
    quantity: number | string,
    unit: string | null | undefined,
    t: TranslateFn,
): QuantityParts {
    const value = typeof quantity === 'string' ? Number(quantity) : quantity;

    return {
        number: Number.isFinite(value)
            ? formatNumber(value, { maximumFractionDigits: 3 })
            : String(quantity),
        unit: unitLabel(unit, t),
    };
}

/**
 * Plain-text quantity for aria labels, titles and exports: "7.6 م", "3 قطعة".
 * The number is wrapped in Unicode LTR isolates so it never reorders inside Arabic text;
 * in JSX prefer `<Quantity>` (a real `<bdi dir="ltr">`).
 */
export function formatQuantity(
    quantity: number | string,
    unit: string | null | undefined,
    t: TranslateFn,
): string {
    const { number, unit: label } = quantityParts(quantity, unit, t);

    return label ? `⁦${number}⁩ ${label}` : `⁦${number}⁩`;
}

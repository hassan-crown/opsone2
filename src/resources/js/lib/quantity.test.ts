import { describe, expect, it } from 'vite-plus/test';
import type { TranslateFn } from './i18n';
import { formatQuantity, quantityParts, unitCode } from './quantity';

const ar: Record<string, string> = {
    'units.meter': 'م',
    'units.piece': 'قطعة',
};
const t = ((key: string) => ar[key] ?? key) as TranslateFn;

describe('quantity', () => {
    it('maps catalogue codes and Arabic labels to one unit', () => {
        expect(unitCode('m')).toBe('meter');
        expect(unitCode(' Unit ')).toBe('piece');
        expect(unitCode('م')).toBe('meter');
        expect(unitCode('درج')).toBeNull();
    });

    it('translates the unit and drops trailing zeros', () => {
        expect(quantityParts('2.900', 'm', t)).toEqual({
            number: '2.9',
            unit: 'م',
        });
        expect(quantityParts(1250, 'unit', t)).toEqual({
            number: '1,250',
            unit: 'قطعة',
        });
    });

    it('keeps a facility unit it does not know as typed', () => {
        expect(quantityParts(3, 'درج', t).unit).toBe('درج');
    });

    it('isolates the number in the plain-text form', () => {
        expect(formatQuantity(7.6, 'm', t)).toBe('⁦7.6⁩ م');
        expect(formatQuantity(2, null, t)).toBe('⁦2⁩');
    });
});

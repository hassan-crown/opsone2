import { describe, expect, it } from 'vite-plus/test';
import { formatDate, formatDateTime } from './format';

describe('formatDate', () => {
    it('keeps Latin digits and the Gregorian calendar in Arabic', () => {
        const text = formatDate('2026-11-01', 'ar');

        expect(text).toMatch(/2026/);
        expect(text).toMatch(/نوفمبر/);
        expect(text).not.toMatch(/[٠-٩]/);
    });

    it('never shifts a date-only value by a day', () => {
        expect(formatDate('2026-11-01', 'en')).toContain('1');
        expect(formatDate('2026-11-01', 'en')).toContain('November');
        expect(formatDate('2026-11-01', 'en')).not.toContain('October');
    });

    it('adds the weekday only in the long style', () => {
        expect(formatDate('2026-11-01', 'en', 'long')).toContain('Sunday');
        expect(formatDate('2026-11-01', 'en', 'short')).not.toContain('Sunday');
    });

    it('returns an unparsable value unchanged', () => {
        expect(formatDate('soon', 'ar')).toBe('soon');
    });

    it('formats date and time with Latin digits', () => {
        expect(formatDateTime('2026-10-29T10:15:00', 'ar')).not.toMatch(
            /[٠-٩]/,
        );
    });
});

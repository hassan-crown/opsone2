import { describe, expect, it } from 'vite-plus/test';
import { initialsOf } from './initials';

describe('initialsOf', () => {
    it('gives one letter for Arabic full names', () => {
        expect(initialsOf('محمد صالح')).toBe('م');
    });

    it('skips the leading definite article', () => {
        expect(initialsOf('الحسن علي')).toBe('ح');
    });

    it('gives one letter for a single Arabic name', () => {
        expect(initialsOf('فاطمة')).toBe('ف');
        expect(initialsOf('الحسن')).toBe('ح');
    });

    it('keeps two letters for Latin names', () => {
        expect(initialsOf('John Smith')).toBe('JS');
        expect(initialsOf('john ronald reuel tolkien')).toBe('JT');
        expect(initialsOf('Madonna')).toBe('M');
    });

    it('decides mixed names by the first name script', () => {
        expect(initialsOf('محمد Smith')).toBe('م');
        expect(initialsOf('John صالح')).toBe('J');
    });

    it('handles empty input', () => {
        expect(initialsOf('   ')).toBe('');
    });
});

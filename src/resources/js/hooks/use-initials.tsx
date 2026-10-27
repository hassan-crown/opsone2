import { useCallback } from 'react';

export type GetInitialsFn = (fullName: string) => string;

/** Arabic definite article ("ال") is skipped so "الحسن" gives "ح", not "ا". */
function getInitial(name: string): string {
    const stripped = name.length > 2 ? name.replace(/^ال/u, '') : name;

    return Array.from(stripped)[0] ?? '';
}

export function useInitials(): GetInitialsFn {
    return useCallback((fullName: string): string => {
        const names = fullName.trim().split(/\s+/u).filter(Boolean);

        if (names.length === 0) {
            return '';
        }

        if (names.length === 1) {
            return getInitial(names[0]).toUpperCase();
        }

        const firstInitial = getInitial(names[0]);
        const lastInitial = getInitial(names[names.length - 1]);

        return `${firstInitial}${lastInitial}`.toUpperCase();
    }, []);
}

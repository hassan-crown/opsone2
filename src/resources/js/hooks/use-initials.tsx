import { useCallback } from 'react';
import { initialsOf } from '@/lib/initials';

export type GetInitialsFn = (fullName: string) => string;

export function useInitials(): GetInitialsFn {
    return useCallback((fullName: string): string => initialsOf(fullName), []);
}

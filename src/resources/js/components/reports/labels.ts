import { useCallback } from 'react';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

/**
 * Translates a dynamic `reports.<group>.<key>` label; falls back to the raw key when the dictionary has no entry
 * (translate() echoes the key back for missing ones).
 */
export function useReportLabel(): (group: string, key: string) => string {
    const t = useT();

    return useCallback(
        (group, key) => {
            const full = `reports.${group}.${key}`;
            const text = t(full as TranslationKey);

            return text === full ? key : text;
        },
        [t],
    );
}

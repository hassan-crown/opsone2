import { useState } from 'react';
import { explainFinance } from '@/components/finance/use-finance-call';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { SettlementResponse } from './types';

const AREAS = ['refunds', 'disputes', 'invoices'] as const;

/**
 * One settlement write: busy state, field errors and a readable refusal. Conditions are looked up in the
 * `refunds.*` / `disputes.*` / `invoices.*` blocks first, then in the finance ones.
 */
export function useSettlementCall() {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const run = async (
        url: string,
        body: Record<string, unknown> | FormData,
    ): Promise<SettlementResponse | null> => {
        setBusy(true);
        setError(null);
        setErrors({});
        const result = await api<SettlementResponse>('POST', url, body);
        setBusy(false);
        if (result.ok && result.data) {
            return result.data;
        }
        setErrors(result.errors);
        const condition = result.body?.condition;
        if (typeof condition === 'string' && !result.stale) {
            const area = AREAS.map(
                (a) => `${a}.condition.${condition}` as TranslationKey,
            ).find((k) => t(k) !== k);
            const generic = condition.startsWith('not_from_')
                ? t('refunds.condition.not_from')
                : null;
            setError(area ? t(area) : (generic ?? explainFinance(result, t)));

            return null;
        }
        setError(explainFinance(result, t));

        return null;
    };

    const fieldError = (field: string): string | undefined =>
        errors[field]?.[0];

    return { busy, error, fieldError, run, setError };
}

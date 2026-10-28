import { useState } from 'react';
import { api } from '@/lib/api';
import type { ApiResult } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslateFn, TranslationKey } from '@/lib/i18n';
import type { FinanceResponse } from './types';

/** A server refusal in the user's language: stale version, a finance condition, a field error or the message. */
export function explainFinance(
    result: ApiResult<unknown>,
    t: TranslateFn,
): string {
    if (result.stale) {
        return t('finance.error.stale');
    }
    const condition = result.body?.condition;
    if (typeof condition === 'string') {
        const key = `finance.condition.${condition}` as TranslationKey;
        const known = t(key);

        return known !== key ? known : t('finance.condition.generic');
    }
    if (result.status === 403) {
        return t('finance.error.forbidden');
    }

    return (
        Object.values(result.errors)[0]?.[0] ??
        result.message ??
        t('finance.error.failed')
    );
}

/** One finance write: busy state, field errors and a readable refusal (and which condition refused it). */
export function useFinanceCall<T = FinanceResponse>() {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [condition, setCondition] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const run = async (
        method: 'POST' | 'PUT',
        url: string,
        body: Record<string, unknown> | FormData,
    ): Promise<T | null> => {
        setBusy(true);
        setError(null);
        setCondition(null);
        setErrors({});
        const result = await api<T>(method, url, body);
        setBusy(false);
        if (result.ok && result.data) {
            return result.data;
        }
        const refused = result.body?.condition;
        setCondition(typeof refused === 'string' ? refused : null);
        setErrors(result.errors);
        setError(explainFinance(result, t));

        return null;
    };

    const fieldError = (field: string): string | undefined =>
        errors[field]?.[0];

    return { busy, error, condition, fieldError, run, setError };
}

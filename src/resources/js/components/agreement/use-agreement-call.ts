import { useState } from 'react';
import { api } from '@/lib/api';
import type { ApiResult } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslateFn, TranslationKey } from '@/lib/i18n';
import type { AgreementPayload } from './types';

export type AgreementResponse = { agreement: AgreementPayload };

/** A server refusal in the user's language: stale version, a machine condition, a field error or the server message. */
export function explainAgreement(
    result: ApiResult<unknown>,
    t: TranslateFn,
): string {
    if (result.stale) {
        return t('agreements.error.stale');
    }
    const condition = result.body?.condition;
    if (typeof condition === 'string') {
        const key = `agreements.condition.${condition}` as TranslationKey;
        const known = t(key);
        if (known !== key) {
            return known;
        }

        return condition.startsWith('not_from_')
            ? t('agreements.condition.not_from')
            : t('agreements.condition.generic');
    }
    if (result.status === 403) {
        return t('agreements.error.forbidden');
    }

    return (
        Object.values(result.errors)[0]?.[0] ??
        result.message ??
        t('agreements.error.failed')
    );
}

/**
 * One write of an agreement screen: busy state, field errors and a readable refusal. `onDone` gets the refreshed
 * agreement payload the endpoint returns.
 */
export function useAgreementCall<T = AgreementResponse>() {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [stale, setStale] = useState(false);

    const run = async (
        method: 'POST' | 'PUT',
        url: string,
        body: Record<string, unknown> | FormData,
    ): Promise<T | null> => {
        setBusy(true);
        setError(null);
        setErrors({});
        const result = await api<T>(method, url, body);
        setBusy(false);
        if (result.ok && result.data) {
            return result.data;
        }
        setStale(result.stale);
        setErrors(result.errors);
        setError(explainAgreement(result, t));

        return null;
    };

    const fieldError = (field: string): string | undefined =>
        errors[field]?.[0];

    return { busy, error, errors, fieldError, stale, run, setError };
}

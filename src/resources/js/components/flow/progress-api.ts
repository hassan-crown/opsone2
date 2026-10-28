import type { TranslateFn, TranslationKey } from '@/lib/i18n';
import type { ApiResult } from '@/lib/api';
import type { Progress } from './types';

export type Member = { id: number; name: string };
export type ProgressResponse = { progress: Progress | null; members: Member[] };

/** Why a call failed, in the user's language: stale version, refused transition, validation or the server text. */
export function explain(result: ApiResult<unknown>, t: TranslateFn): string {
    if (result.stale) {
        return t('progress.stale');
    }
    const condition = result.body?.condition;
    if (typeof condition === 'string') {
        const key = `progress.condition.${condition}` as TranslationKey;
        const known = t(key);
        if (known !== key) {
            return known;
        }

        return condition.startsWith('not_from_')
            ? t('progress.condition.not_from')
            : t('progress.condition.generic');
    }

    return (
        Object.values(result.errors)[0]?.[0] ??
        result.message ??
        t('progress.failed')
    );
}

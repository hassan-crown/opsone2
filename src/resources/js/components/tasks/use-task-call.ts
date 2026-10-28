import { router } from '@inertiajs/react';
import { useState } from 'react';
import TaskActionController from '@/actions/App/Http/Controllers/Tasks/TaskActionController';
import { notify } from '@/components/opsone/toast';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type TaskVerb =
    | 'claim'
    | 'assign'
    | 'reassign'
    | 'start'
    | 'wait'
    | 'resolve'
    | 'complete'
    | 'cancel';

const URLS: Record<TaskVerb, (id: number) => string> = {
    claim: (id) => TaskActionController.claim.url(id),
    assign: (id) => TaskActionController.assign.url(id),
    reassign: (id) => TaskActionController.reassign.url(id),
    start: (id) => TaskActionController.start.url(id),
    wait: (id) => TaskActionController.wait.url(id),
    resolve: (id) => TaskActionController.resolve.url(id),
    complete: (id) => TaskActionController.complete.url(id),
    cancel: (id) => TaskActionController.cancel.url(id),
};

export type CallResult = { ok: boolean; errors: Record<string, string[]> };

/**
 * One call to an M-07 transition. Success reloads the page props; a 409 (lost claim, stale version, wrong state)
 * and other refusals are explained in a toast and the page reloads so the user sees the current state.
 */
export function useTaskCall() {
    const t = useT();
    const [busy, setBusy] = useState(false);

    const call = async (
        verb: TaskVerb,
        id: number,
        body: Record<string, unknown> = {},
        options: { onDone?: () => void } = {},
    ): Promise<CallResult> => {
        if (busy) {
            return { ok: false, errors: {} };
        }
        setBusy(true);
        const result = await api('POST', URLS[verb](id), body);
        setBusy(false);

        if (result.ok) {
            notify('success', {
                title: t(`tasks.done.${verb}` as TranslationKey),
            });
            options.onDone?.();
            router.reload();

            return { ok: true, errors: {} };
        }
        if (result.status === 422) {
            return { ok: false, errors: result.errors };
        }
        notify('danger', {
            title: result.stale
                ? t('tasks.error.stale')
                : result.status === 409
                  ? t('tasks.error.taken')
                  : (result.message ?? t('tasks.error.failed')),
        });
        router.reload();

        return { ok: false, errors: {} };
    };

    return { call, busy };
}

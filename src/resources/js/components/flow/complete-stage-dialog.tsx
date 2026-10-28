import { useState } from 'react';
import OrderProgressController from '@/actions/App/Http/Controllers/Flow/OrderProgressController';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { explain } from './progress-api';
import type { ProgressResponse } from './progress-api';
import { ReasonDialog } from './reason-dialog';
import type { Progress, ProgressAction, ProgressStage } from './types';

const OPEN = ['ready', 'awaiting_result', 'needs_rework', 'unavailable'];

/**
 * `OrderCompleteStage`: completing a stage while a required result is still open. Each open requirement is listed;
 * the owner or manager may allow proceeding with a written reason (P-35) and the stage then completes.
 */
export function CompleteStageDialog({
    orderId,
    stage,
    progress,
    onClose,
    onChanged,
}: {
    orderId: number;
    stage: ProgressStage;
    progress: Progress;
    onClose: () => void;
    onChanged: (next: ProgressResponse) => void;
}) {
    const t = useT();
    const [allowing, setAllowing] = useState<ProgressAction | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const current = progress.stages.find((s) => s.id === stage.id) ?? stage;
    const open = current.actions.filter(
        (a) =>
            a.requirement === 'required' && (OPEN.includes(a.status) || a.held),
    );

    const complete = async () => {
        setBusy(true);
        setError(null);
        const result = await api<ProgressResponse>(
            'POST',
            OrderProgressController.completeStage.url({
                order: orderId,
                stage: stage.id,
            }),
            { lock_version: progress.lock_version },
        );
        setBusy(false);
        if (result.ok && result.data) {
            notify('success', { title: t('progress.complete.done') });
            onChanged(result.data);
            onClose();

            return;
        }
        setError(explain(result, t));
    };

    return (
        <Dialog open onOpenChange={(o) => !o && !allowing && onClose()}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {t('progress.complete.title', { name: current.name })}
                    </DialogTitle>
                    <DialogDescription>
                        {open.length === 0
                            ? t('progress.complete.ready')
                            : t('progress.complete.open_intro')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3" data-test="complete-form">
                    {error && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{error}</AlertBody>
                        </Alert>
                    )}
                    {open.map((a) => (
                        <div
                            key={a.id}
                            className="border-warning flex flex-wrap items-center justify-between gap-2 rounded-md border bg-warning-bg p-3"
                            data-test={`open-${a.key}`}
                        >
                            <div>
                                <p className="text-label text-strong">
                                    {a.name}
                                </p>
                                <Badge variant="warning">
                                    {a.held
                                        ? t('progress.blockers.held')
                                        : t(
                                              `progress.action.${a.status}` as TranslationKey,
                                          )}
                                </Badge>
                            </div>
                            {a.can.allow ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setAllowing(a)}
                                    data-test={`allow-${a.key}`}
                                >
                                    {t('progress.allow.action')}
                                </Button>
                            ) : (
                                <span className="text-caption text-weak">
                                    {t('progress.allow.not_permitted')}
                                </span>
                            )}
                        </div>
                    ))}
                </div>
                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('flow.cancel')}
                    </Button>
                    <Button
                        type="button"
                        loading={busy}
                        disabled={open.length > 0}
                        onClick={complete}
                        data-test="complete-confirm"
                    >
                        {t('progress.complete.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
            {allowing && (
                <ReasonDialog
                    title={t('progress.allow.title')}
                    description={t('progress.allow.intro', {
                        name: allowing.name,
                    })}
                    reasonLabel={t('progress.allow.reason')}
                    confirmLabel={t('progress.allow.confirm')}
                    url={OrderProgressController.allow.url({
                        order: orderId,
                        action: allowing.id,
                    })}
                    lockVersion={progress.lock_version}
                    onClose={() => setAllowing(null)}
                    onDone={(next) => {
                        notify('success', { title: t('progress.allow.done') });
                        setAllowing(null);
                        onChanged(next);
                    }}
                />
            )}
        </Dialog>
    );
}

import {
    CircleCheck,
    CirclePause,
    CirclePlay,
    Clock,
    ListChecks,
    Pencil,
    RotateCcw,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import OrderProgressController from '@/actions/App/Http/Controllers/Flow/OrderProgressController';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { CompleteStageDialog } from './complete-stage-dialog';
import { PauseDialog } from './pause-dialog';
import { explain } from './progress-api';
import type { ProgressResponse } from './progress-api';
import { ReasonDialog } from './reason-dialog';
import type { ProgressAction, ProgressStage } from './types';
import { UpcomingEditor } from './upcoming-editor';

const STAGE_TONE = {
    not_started: 'neutral',
    in_progress: 'info',
    waiting: 'warning',
    completed: 'success',
    completed_needs_correction: 'warning',
} as const;

const ACTION_TONE = {
    not_required: 'neutral',
    unavailable: 'neutral',
    ready: 'info',
    awaiting_result: 'warning',
    verified: 'success',
    allowed: 'warning',
    needs_rework: 'danger',
} as const;

/**
 * `OrderStages` (+ `OrderCompleteStage`, `OrderPause`, `OrderRework`, `OrderEditUpcoming`): the order's stages and
 * actions on the flow version it started with, what blocks the current stage and who is responsible. Every button is
 * shown from the server's `can` flags and re-checked on the server; no money appears here.
 */
export function OrderStages({
    orderId,
    orderStatus,
    onChanged,
}: {
    orderId: number;
    orderStatus: string;
    onChanged: () => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [data, setData] = useState<ProgressResponse | null>(null);
    const [failed, setFailed] = useState(false);
    const [completing, setCompleting] = useState<ProgressStage | null>(null);
    const [reworking, setReworking] = useState<ProgressAction | null>(null);
    const [pausing, setPausing] = useState(false);
    const [editing, setEditing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const result = await api<ProgressResponse>(
            'GET',
            OrderProgressController.show.url(orderId),
        );
        if (result.ok && result.data) {
            setData(result.data);
            setFailed(false);
        } else {
            setFailed(true);
        }
    }, [orderId]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const absorb = (next: ProgressResponse) => {
        setData(next);
        onChanged();
    };

    if (failed) {
        return (
            <Alert variant="danger" role="alert">
                <AlertBody>{t('progress.load_failed')}</AlertBody>
            </Alert>
        );
    }
    if (data === null) {
        return <Skeleton className="h-40 w-full" />;
    }
    const progress = data.progress;
    if (progress === null) {
        return (
            <Card>
                <p className="text-weak">{t('progress.none')}</p>
            </Card>
        );
    }

    const running = orderStatus === 'in_progress' || orderStatus === 'paused';
    const personName = (id: number | null) =>
        data.members.find((m) => m.id === id)?.name ?? null;
    const canFinish =
        progress.can.finish_execution &&
        orderStatus === 'in_progress' &&
        progress.active_stage_id === null &&
        progress.stages.every((s) => s.status === 'completed');

    const resume = async () => {
        setBusy(true);
        setError(null);
        const result = await api<ProgressResponse>(
            'POST',
            OrderProgressController.resume.url(orderId),
            { lock_version: progress.lock_version },
        );
        setBusy(false);
        if (result.ok && result.data) {
            notify('success', { title: t('progress.resumed') });
            absorb(result.data);

            return;
        }
        setError(explain(result, t));
    };

    const finish = async () => {
        setBusy(true);
        setError(null);
        const result = await api<ProgressResponse>(
            'POST',
            OrderProgressController.finishExecution.url(orderId),
            { lock_version: progress.lock_version },
        );
        setBusy(false);
        if (result.ok && result.data) {
            notify('success', { title: t('progress.finished') });
            absorb(result.data);

            return;
        }
        setError(explain(result, t));
    };

    if (editing) {
        return (
            <UpcomingEditor
                orderId={orderId}
                lockVersion={progress.lock_version}
                onCancel={() => setEditing(false)}
                onSaved={(next) => {
                    setEditing(false);
                    absorb(next);
                }}
            />
        );
    }

    return (
        <div className="grid gap-4" data-test="order-stages">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex flex-wrap items-center gap-2 text-weak">
                    <Badge variant="neutral" data-test="pinned-version">
                        {t('progress.pinned_version', {
                            n: progress.flow_version,
                        })}
                    </Badge>
                    <span className="text-caption">
                        {t('progress.pinned_hint')}
                    </span>
                </p>
                <div className="flex flex-wrap gap-2">
                    {running && progress.can.edit_upcoming && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setEditing(true)}
                            data-test="edit-upcoming"
                        >
                            <Icon icon={Pencil} />
                            {t('progress.edit_upcoming.action')}
                        </Button>
                    )}
                    {running && progress.can.pause && !progress.paused && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setPausing(true)}
                            data-test="pause-order"
                        >
                            <Icon icon={CirclePause} />
                            {t('progress.pause.action')}
                        </Button>
                    )}
                    {progress.paused && progress.can.pause && (
                        <Button
                            type="button"
                            loading={busy}
                            onClick={resume}
                            data-test="resume-order"
                        >
                            <Icon icon={CirclePlay} />
                            {t('progress.resume.action')}
                        </Button>
                    )}
                    {canFinish && (
                        <Button
                            type="button"
                            loading={busy}
                            onClick={finish}
                            data-test="finish-execution"
                        >
                            <Icon icon={CircleCheck} />
                            {t('progress.finish.action')}
                        </Button>
                    )}
                </div>
            </div>

            {error && (
                <Alert variant="danger" role="alert">
                    <AlertBody>{error}</AlertBody>
                </Alert>
            )}

            {progress.paused && progress.pause && (
                <Alert variant="warning" data-test="pause-banner">
                    <AlertBody>
                        <p className="font-semibold">
                            {t('progress.pause.banner')}
                        </p>
                        <p>{progress.pause.reason}</p>
                        <p className="text-caption">
                            {[
                                personName(progress.pause.follow_up_user_id)
                                    ? t('progress.pause.follower', {
                                          name:
                                              personName(
                                                  progress.pause
                                                      .follow_up_user_id,
                                              ) ?? '',
                                      })
                                    : null,
                                progress.pause.review_on
                                    ? t('progress.pause.review_on', {
                                          date: formatDate(
                                              progress.pause.review_on,
                                              locale,
                                          ),
                                      })
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    </AlertBody>
                </Alert>
            )}

            {progress.blockers.length > 0 && !progress.paused && (
                <Card className="border-warning gap-2" data-test="blockers">
                    <h3 className="flex items-center gap-2 text-label text-strong">
                        <Icon icon={Clock} size="sm" />
                        {t('progress.blockers.title')}
                    </h3>
                    <ul className="m-0 grid list-none gap-1 p-0">
                        {progress.blockers.map((b) => (
                            <li
                                key={b.action}
                                className="text-body text-strong"
                            >
                                {b.name}
                                <span className="text-weak">
                                    {' · '}
                                    {b.held
                                        ? t('progress.blockers.held')
                                        : t(
                                              `progress.action.${b.status}` as TranslationKey,
                                          )}
                                    {personName(b.responsible_user_id)
                                        ? ` · ${personName(b.responsible_user_id)}`
                                        : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <ol className="m-0 grid list-none gap-3 p-0">
                {progress.stages.map((stage) => (
                    <li key={stage.id}>
                        <Card
                            className={cn(
                                'gap-3',
                                stage.id === progress.active_stage_id &&
                                    'border-brand',
                            )}
                            data-test={`order-stage-${stage.key}`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex min-w-0 flex-wrap items-center gap-2">
                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-fill text-label text-strong">
                                        {stage.position}
                                    </span>
                                    <h3 className="text-subtitle text-strong">
                                        {stage.name}
                                    </h3>
                                    <Badge
                                        variant={
                                            STAGE_TONE[
                                                stage.status as keyof typeof STAGE_TONE
                                            ] ?? 'neutral'
                                        }
                                    >
                                        {t(
                                            `progress.stage.${stage.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                    {stage.due_on && (
                                        <span className="text-caption text-weak">
                                            {t('progress.stage.due', {
                                                date: formatDate(
                                                    stage.due_on,
                                                    locale,
                                                ),
                                            })}
                                        </span>
                                    )}
                                </div>
                                {stage.can_complete &&
                                    running &&
                                    !progress.paused && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => setCompleting(stage)}
                                            data-test={`complete-${stage.key}`}
                                        >
                                            <Icon icon={ListChecks} size="sm" />
                                            {t('progress.complete.action')}
                                        </Button>
                                    )}
                            </div>
                            <ul className="m-0 grid list-none gap-2 p-0">
                                {stage.actions.map((action) => (
                                    <li
                                        key={action.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-stroke-weak p-3"
                                        data-test={`order-action-${action.key}`}
                                    >
                                        <div className="min-w-0">
                                            <p className="text-label text-strong">
                                                {action.name}
                                            </p>
                                            <p className="flex flex-wrap items-center gap-2 text-caption text-weak">
                                                <Badge
                                                    variant={
                                                        ACTION_TONE[
                                                            action.status as keyof typeof ACTION_TONE
                                                        ] ?? 'neutral'
                                                    }
                                                >
                                                    {t(
                                                        `progress.action.${action.status}` as TranslationKey,
                                                    )}
                                                </Badge>
                                                <span>
                                                    {t(
                                                        `flow.requirement.${action.requirement}` as TranslationKey,
                                                    )}
                                                </span>
                                                {action.held && (
                                                    <span>
                                                        {t(
                                                            'progress.blockers.held',
                                                        )}
                                                    </span>
                                                )}
                                                {action.rework_count > 0 && (
                                                    <span>
                                                        {t(
                                                            'progress.action.rework_count',
                                                            {
                                                                n: action.rework_count,
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                                {action.allowed_reason && (
                                                    <span>
                                                        {t(
                                                            'progress.action.allowed_reason',
                                                            {
                                                                reason: action.allowed_reason,
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        {action.can.rework &&
                                            running &&
                                            !progress.paused && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setReworking(action)
                                                    }
                                                    data-test={`rework-${action.key}`}
                                                >
                                                    <Icon
                                                        icon={RotateCcw}
                                                        size="sm"
                                                    />
                                                    {t(
                                                        'progress.rework.action',
                                                    )}
                                                </Button>
                                            )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </li>
                ))}
            </ol>

            {completing && (
                <CompleteStageDialog
                    orderId={orderId}
                    stage={completing}
                    progress={progress}
                    onClose={() => setCompleting(null)}
                    onChanged={absorb}
                />
            )}
            {reworking && (
                <ReasonDialog
                    title={t('progress.rework.title')}
                    description={t('progress.rework.intro', {
                        name: reworking.name,
                    })}
                    reasonLabel={t('progress.rework.reason')}
                    confirmLabel={t('progress.rework.confirm')}
                    url={OrderProgressController.rework.url({
                        order: orderId,
                        action: reworking.id,
                    })}
                    lockVersion={progress.lock_version}
                    onClose={() => setReworking(null)}
                    onDone={(next) => {
                        notify('success', { title: t('progress.rework.done') });
                        setReworking(null);
                        absorb(next);
                    }}
                />
            )}
            {pausing && (
                <PauseDialog
                    orderId={orderId}
                    lockVersion={progress.lock_version}
                    members={data.members}
                    onClose={() => setPausing(false)}
                    onDone={(next) => {
                        notify('success', { title: t('progress.pause.done') });
                        setPausing(false);
                        absorb(next);
                    }}
                />
            )}
        </div>
    );
}

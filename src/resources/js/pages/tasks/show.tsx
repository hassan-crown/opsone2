import { Head, Link, router } from '@inertiajs/react';
import { Paperclip, Ruler } from 'lucide-react';
import { useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import TaskAttachmentController from '@/actions/App/Http/Controllers/Tasks/TaskAttachmentController';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import TaskMeasurementController from '@/actions/App/Http/Controllers/Tasks/TaskMeasurementController';
import { TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { CancelDialog, WaitDialog } from '@/components/tasks/dialogs';
import { TaskStatusBadge, useDueLabel } from '@/components/tasks/status';
import type { TaskDetail } from '@/components/tasks/types';
import { useTaskCall } from '@/components/tasks/use-task-call';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

function Section({
    title,
    children,
    testId,
}: {
    title: string;
    children: React.ReactNode;
    testId?: string;
}) {
    return (
        <Card className="gap-3 p-5" data-test={testId}>
            <h2 className="text-control font-semibold text-strong">{title}</h2>
            {children}
        </Card>
    );
}

/** TaskPage / TaskMobile / TaskEnglish / TaskDark: one responsive page; the executor sees the task's own data only (WF-08). */
export default function TaskShow({ task }: { task: TaskDetail }) {
    const t = useT();
    const { locale } = useLocale();
    const due = useDueLabel();
    const { call, busy } = useTaskCall();
    const [note, setNote] = useState(task.result_note ?? '');
    const [waiting, setWaiting] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const fileRef = useRef<HTMLInputElement>(null);
    const open = task.status !== 'completed' && task.status !== 'cancelled';
    const measuring = task.action?.completion === 'measurement';
    const finals = task.measurements.filter((m) => m.kind === 'final').length;

    const complete = async () => {
        const result = await call('complete', task.id, {
            result_note: note || null,
            lock_version: task.lock_version,
        });
        setErrors(result.errors);
    };
    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }
        router.post(
            TaskAttachmentController.store.url(task.id),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () =>
                    notify('success', { title: t('tasks.attach.done') }),
                onError: (e) =>
                    notify('danger', {
                        title: Object.values(e)[0] ?? t('tasks.error.failed'),
                    }),
            },
        );
        if (fileRef.current) {
            fileRef.current.value = '';
        }
    };

    return (
        <>
            <Head title={task.title} />
            <div
                className="mx-auto flex max-w-5xl flex-col gap-4"
                data-test="task-page"
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-title text-strong">{task.title}</h1>
                        <p className="mt-1 text-body text-weak">
                            {t('tasks.order')}{' '}
                            {task.order.viewable ? (
                                <Link
                                    href={OrderController.show(task.order.id)}
                                    className="text-link hover:underline"
                                >
                                    <bdi dir="ltr">{task.order.number}</bdi>
                                </Link>
                            ) : (
                                <bdi dir="ltr">{task.order.number}</bdi>
                            )}
                            {' · '}
                            {task.stage}
                            {task.assignee && <> · {task.assignee.name}</>}
                        </p>
                    </div>
                    <TaskStatusBadge status={task.status} />
                </div>

                {task.status === 'waiting' && (
                    <Alert variant="warning" data-test="task-waiting-note">
                        <AlertBody>
                            <p className="font-semibold">
                                {t('tasks.waiting.title')}
                            </p>
                            <p>{task.waiting_need}</p>
                            {task.waiting_follow_up_on && (
                                <p className="text-caption">
                                    {t('tasks.waiting.follow_up', {
                                        date: formatDate(
                                            task.waiting_follow_up_on,
                                            locale,
                                            { dateStyle: 'medium' },
                                        ),
                                    })}
                                </p>
                            )}
                        </AlertBody>
                    </Alert>
                )}
                {errors.attachments && (
                    <Alert variant="danger" role="alert">
                        <AlertBody>{errors.attachments[0]}</AlertBody>
                    </Alert>
                )}
                {errors.measurements && (
                    <Alert variant="danger" role="alert">
                        <AlertBody>{errors.measurements[0]}</AlertBody>
                    </Alert>
                )}

                <div className="grid gap-4 md:grid-cols-[1fr_320px]">
                    <div className="flex flex-col gap-4">
                        <Section title={t('tasks.required')}>
                            <p className="text-body text-weak">
                                {task.action?.name ?? task.title}
                            </p>
                            {task.lines.length > 0 && (
                                <ul
                                    className="divide-y divide-stroke-weak text-body"
                                    aria-label={t('tasks.lines')}
                                >
                                    {task.lines.map((l) => (
                                        <li
                                            key={l.id}
                                            className="flex justify-between gap-3 py-2"
                                        >
                                            <span>{l.name}</span>
                                            <span className="text-weak">
                                                <bdi dir="ltr">
                                                    {l.quantity}
                                                </bdi>{' '}
                                                {l.unit ?? ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="text-caption text-weak">
                                {t('tasks.due.label')}: {due(task)}
                            </p>
                        </Section>

                        {measuring && (
                            <Section
                                title={t('tasks.measure.title')}
                                testId="task-measure-card"
                            >
                                <p className="text-body text-weak">
                                    {t('tasks.measure.summary', {
                                        count: task.measurements.length,
                                        final: finals,
                                    })}
                                </p>
                                {task.can.measure && (
                                    <Button asChild block>
                                        <Link
                                            href={TaskMeasurementController.index(
                                                task.id,
                                            )}
                                        >
                                            <Icon icon={Ruler} />
                                            {t('tasks.measure.open')}
                                        </Link>
                                    </Button>
                                )}
                            </Section>
                        )}

                        <Section
                            title={t('tasks.attachments')}
                            testId="task-attachments"
                        >
                            {task.action?.requires_attachment && (
                                <p className="text-caption text-weak">
                                    {t('tasks.attach.required')}
                                </p>
                            )}
                            {task.attachments.length === 0 ? (
                                <p className="text-body text-weak">
                                    {t('tasks.attach.none')}
                                </p>
                            ) : (
                                <ul className="grid gap-2">
                                    {task.attachments.map((a) => (
                                        <li key={a.uuid}>
                                            <a
                                                href={a.url}
                                                className="inline-flex items-center gap-2 text-link hover:underline"
                                            >
                                                <Icon
                                                    icon={Paperclip}
                                                    size="sm"
                                                />
                                                {a.name}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {task.can_attach && (
                                <>
                                    <input
                                        ref={fileRef}
                                        type="file"
                                        className="sr-only"
                                        id="task-file"
                                        onChange={(e) =>
                                            upload(e.target.files?.[0])
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => fileRef.current?.click()}
                                        data-test="task-upload"
                                    >
                                        {t('tasks.attach.add')}
                                    </Button>
                                </>
                            )}
                        </Section>

                        {open && task.can.complete && (
                            <Section title={t('tasks.result')}>
                                <TextareaField
                                    label={t('tasks.result_note')}
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    maxLength={2000}
                                    optional
                                    hint={t('tasks.result_hint')}
                                />
                            </Section>
                        )}
                        {task.status === 'completed' && task.result_note && (
                            <Section title={t('tasks.result')}>
                                <p>{task.result_note}</p>
                            </Section>
                        )}
                    </div>

                    <div className="flex flex-col gap-4">
                        {task.visit && (
                            <Section
                                title={t('tasks.visit')}
                                testId="task-visit"
                            >
                                <dl className="grid gap-1 text-body">
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-weak">
                                            {t('tasks.visit.when')}
                                        </dt>
                                        <dd>
                                            {formatDateTime(
                                                task.visit.starts_at,
                                                locale,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-weak">
                                            {t('tasks.visit.customer')}
                                        </dt>
                                        <dd>{task.visit.customer}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-weak">
                                            {t('tasks.visit.address')}
                                        </dt>
                                        <dd className="text-end">
                                            {task.visit.address}
                                        </dd>
                                    </div>
                                </dl>
                                <Button asChild variant="secondary" block>
                                    <a
                                        href={task.visit.maps_url}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        {t('tasks.maps')}
                                    </a>
                                </Button>
                            </Section>
                        )}

                        <Section title={t('tasks.hidden.title')}>
                            <p className="text-body text-weak">
                                {task.follow_up
                                    ? t('tasks.hidden.body', {
                                          name: task.follow_up,
                                      })
                                    : t('tasks.hidden.body_plain')}
                            </p>
                        </Section>
                    </div>
                </div>

                {open && (
                    <div
                        className="sticky bottom-0 -mx-4 flex flex-wrap gap-2 border-t border-stroke-weak bg-background px-4 py-3 md:static md:mx-0 md:border-0 md:px-0"
                        data-test="task-actions"
                    >
                        {task.can.start && (
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() =>
                                    call('start', task.id, {
                                        lock_version: task.lock_version,
                                    })
                                }
                                data-test="task-start"
                                className="max-md:flex-1"
                            >
                                {t('tasks.action.start')}
                            </Button>
                        )}
                        {task.can.claim && (
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() => call('claim', task.id)}
                                data-test="task-claim"
                                className="max-md:flex-1"
                            >
                                {t('tasks.action.claim')}
                            </Button>
                        )}
                        {task.can.complete && (
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={complete}
                                data-test="task-complete"
                                className="max-md:flex-1"
                            >
                                {t('tasks.action.complete')}
                            </Button>
                        )}
                        {task.can.resolve && (
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() =>
                                    call('resolve', task.id, {
                                        lock_version: task.lock_version,
                                    })
                                }
                                data-test="task-resolve"
                                className="max-md:flex-1"
                            >
                                {t('tasks.action.resolve')}
                            </Button>
                        )}
                        {task.can.wait && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setWaiting(true)}
                                data-test="task-wait"
                                className="max-md:flex-1"
                            >
                                {t('tasks.action.wait')}
                            </Button>
                        )}
                        {(task.can.assign || task.can.reassign) && (
                            <Button
                                asChild
                                variant="secondary"
                                className="max-md:flex-1"
                            >
                                <Link href={TaskController.assign(task.id)}>
                                    {t(
                                        (task.can.reassign
                                            ? 'tasks.action.reassign'
                                            : 'tasks.action.assign') as TranslationKey,
                                    )}
                                </Link>
                            </Button>
                        )}
                        {task.can.cancel && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setCancelling(true)}
                                className="max-md:flex-1"
                            >
                                {t('tasks.action.cancel')}
                            </Button>
                        )}
                    </div>
                )}
            </div>
            <WaitDialog task={task} open={waiting} onOpenChange={setWaiting} />
            <CancelDialog
                task={task}
                open={cancelling}
                onOpenChange={setCancelling}
            />
        </>
    );
}

TaskShow.layout = {
    breadcrumbs: [{ title: 'tasks.nav', href: TaskController.index() }],
};

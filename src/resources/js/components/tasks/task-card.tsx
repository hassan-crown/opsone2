import { Link } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { TaskStatusBadge, useDueLabel } from './status';
import type { TaskRow } from './types';
import { useTaskCall } from './use-task-call';

/** Primary action of a row: take it from the inbox, start it, or open it. */
export function TaskRowAction({
    task,
    block = false,
}: {
    task: TaskRow;
    block?: boolean;
}) {
    const t = useT();
    const { call, busy } = useTaskCall();

    if (task.can.claim) {
        return (
            <Button
                type="button"
                size="sm"
                block={block}
                disabled={busy}
                data-test="task-claim"
                onClick={() => call('claim', task.id)}
            >
                {t('tasks.action.claim')}
            </Button>
        );
    }
    if (task.can.assign) {
        return (
            <Button asChild size="sm" variant="secondary" block={block}>
                <Link href={TaskController.assign(task.id)}>
                    {t('tasks.action.assign')}
                </Link>
            </Button>
        );
    }
    if (task.can.start) {
        return (
            <Button
                type="button"
                size="sm"
                block={block}
                disabled={busy}
                data-test="task-start"
                onClick={() =>
                    call('start', task.id, { lock_version: task.lock_version })
                }
            >
                {t('tasks.action.start')}
            </Button>
        );
    }

    return (
        <Button asChild size="sm" variant="secondary" block={block}>
            <Link href={TaskController.show(task.id)}>
                {t('tasks.action.open')}
            </Link>
        </Button>
    );
}

/** Mobile-first card of one task (TaskList on a phone, ExecHomeMobile, home cards). */
export function TaskCard({ task }: { task: TaskRow }) {
    const t = useT();
    const { locale } = useLocale();
    const due = useDueLabel();

    return (
        <li
            className="flex flex-col gap-3 rounded-lg border border-stroke-weak bg-surface p-4"
            data-test="task-card"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={TaskController.show(task.id)}
                        className="text-control font-semibold text-link hover:underline"
                    >
                        {task.title}
                    </Link>
                    <p className="text-caption text-weak">
                        <bdi dir="ltr">{task.order.number}</bdi>
                        {' · '}
                        {task.order.customer}
                    </p>
                </div>
                <TaskStatusBadge status={task.status} />
            </div>
            <p
                className={
                    task.overdue
                        ? 'text-body font-semibold text-danger-text'
                        : 'text-body text-weak'
                }
            >
                {task.visit
                    ? formatDate(task.visit.starts_at, locale, {
                          weekday: 'short',
                          hour: '2-digit',
                          minute: '2-digit',
                      })
                    : due(task)}
                {' · '}
                {task.stage}
            </p>
            {task.visit && (
                <p className="flex items-center gap-1 text-caption text-weak">
                    <Icon icon={MapPin} size="sm" />
                    {task.visit.address}
                </p>
            )}
            <div className="flex gap-2 *:flex-1">
                <TaskRowAction task={task} />
                {task.visit && (
                    <Button asChild size="sm" variant="secondary">
                        <a
                            href={task.visit.maps_url}
                            target="_blank"
                            rel="noreferrer noopener"
                        >
                            {t('tasks.maps')}
                        </a>
                    </Button>
                )}
            </div>
        </li>
    );
}

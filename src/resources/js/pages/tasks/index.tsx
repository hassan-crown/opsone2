import { Head, Link, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { TaskCard, TaskRowAction } from '@/components/tasks/task-card';
import { TaskStatusBadge, useDueLabel } from '@/components/tasks/status';
import type { TaskRow } from '@/components/tasks/types';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Filters = { q: string; status: string; overdue: boolean };

type Props = {
    tasks: TaskRow[];
    counts: Record<string, number>;
    view: string;
    filters: Filters;
    limit: number;
};

const VIEWS = [
    'all',
    'mine',
    'inbox',
    'waiting',
    'followed',
    'completed',
] as const;

/** TaskList / TaskEmpty: my work, the department inbox, work waiting for a need and unassigned work I follow. */
export default function TasksIndex({
    tasks,
    counts,
    view,
    filters,
    limit,
}: Props) {
    const t = useT();
    const due = useDueLabel();
    const [q, setQ] = useState(filters.q);
    const url = TaskController.index.url();

    const go = (
        next: Partial<{
            view: string;
            q: string;
            status: string;
            overdue: boolean;
        }>,
    ) => {
        const merged = {
            view,
            q: filters.q,
            status: filters.status,
            overdue: filters.overdue,
            ...next,
        };
        router.get(
            url,
            Object.fromEntries(
                Object.entries(merged).filter(
                    ([k, v]) =>
                        v !== '' &&
                        v !== false &&
                        !(k === 'view' && v === 'all'),
                ),
            ),
            { preserveScroll: true },
        );
    };
    const search = (e: FormEvent) => {
        e.preventDefault();
        go({ q });
    };
    const filtered =
        filters.q !== '' || filters.status !== '' || filters.overdue;
    const visibleViews = VIEWS.filter(
        (v) => v !== 'followed' || counts.followed > 0,
    );

    return (
        <>
            <Head title={t('tasks.title')} />
            <PageHeader
                title={t('tasks.title')}
                description={t('tasks.description')}
            />

            <div
                role="tablist"
                aria-label={t('tasks.title')}
                className="mb-3 flex flex-wrap gap-2"
                data-test="task-views"
            >
                {visibleViews.map((v) => (
                    <Button
                        key={v}
                        type="button"
                        role="tab"
                        size="sm"
                        variant={view === v ? 'default' : 'secondary'}
                        aria-selected={view === v}
                        data-test={`task-view-${v}`}
                        onClick={() => go({ view: v })}
                    >
                        {t(`tasks.view.${v}` as TranslationKey)}{' '}
                        {counts[v] ?? 0}
                    </Button>
                ))}
            </div>

            <form
                onSubmit={search}
                className="mb-3 flex flex-wrap items-center gap-2"
                role="search"
            >
                <Input
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('tasks.search')}
                    aria-label={t('tasks.search')}
                    className="w-full sm:max-w-sm sm:flex-1"
                />
                <Button type="submit" size="sm" variant="secondary">
                    {t('tasks.search_button')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant={filters.overdue ? 'default' : 'secondary'}
                    aria-pressed={filters.overdue}
                    onClick={() => go({ overdue: !filters.overdue })}
                >
                    {t('tasks.filter.overdue')}
                </Button>
                {filtered && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setQ('');
                            go({ q: '', status: '', overdue: false });
                        }}
                    >
                        {t('tasks.filter.clear')}
                    </Button>
                )}
            </form>

            {tasks.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={ListChecks}
                        title={
                            filtered ? t('tasks.no_results') : t('tasks.empty')
                        }
                        description={
                            filtered
                                ? t('tasks.no_results_desc')
                                : t('tasks.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <>
                    <ul className="grid gap-3 md:hidden" data-test="task-cards">
                        {tasks.map((task) => (
                            <TaskCard key={task.id} task={task} />
                        ))}
                    </ul>
                    <Card className="hidden gap-0 p-0 md:block md:p-0">
                        <TableWrap>
                            <Table caption={t('tasks.caption')}>
                                <thead>
                                    <tr>
                                        <Th>{t('tasks.col.task')}</Th>
                                        <Th>{t('tasks.col.order')}</Th>
                                        <Th>{t('tasks.col.customer')}</Th>
                                        <Th>{t('tasks.col.stage')}</Th>
                                        <Th>{t('tasks.col.due')}</Th>
                                        <Th>{t('tasks.col.status')}</Th>
                                        <Th>{t('tasks.col.action')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tasks.map((task) => (
                                        <tr key={task.id} data-test="task-row">
                                            <Td>
                                                <Link
                                                    href={TaskController.show(
                                                        task.id,
                                                    )}
                                                    className="font-semibold text-link hover:underline"
                                                >
                                                    {task.title}
                                                </Link>
                                            </Td>
                                            <Td>
                                                <bdi dir="ltr">
                                                    {task.order.number}
                                                </bdi>
                                            </Td>
                                            <Td>{task.order.customer}</Td>
                                            <Td>{task.stage}</Td>
                                            <Td
                                                className={
                                                    task.overdue
                                                        ? 'font-semibold text-danger-text'
                                                        : ''
                                                }
                                            >
                                                {task.status === 'waiting' &&
                                                task.waiting_need
                                                    ? task.waiting_need
                                                    : due(task)}
                                            </Td>
                                            <Td>
                                                <TaskStatusBadge
                                                    status={task.status}
                                                />
                                            </Td>
                                            <Td>
                                                <TaskRowAction task={task} />
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                    </Card>
                    {tasks.length >= limit && (
                        <p className="mt-3 text-caption text-weak">
                            {t('tasks.limit', { limit })}
                        </p>
                    )}
                </>
            )}
        </>
    );
}

TasksIndex.layout = {
    breadcrumbs: [{ title: 'tasks.nav', href: TaskController.index() }],
};

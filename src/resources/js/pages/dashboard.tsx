import { Head, Link, usePage } from '@inertiajs/react';
import { BellRing, ListChecks } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { SetupChecklist } from '@/components/org/setup-checklist';
import type { SetupSummary } from '@/components/org/setup-checklist';
import { Card } from '@/components/ui/card';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { ExecHome } from '@/components/tasks/exec-home';
import type { ExecHomeData } from '@/components/tasks/exec-home';
import type { TaskRow } from '@/components/tasks/types';
import { useDueLabel } from '@/components/tasks/status';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { dashboard } from '@/routes';

type Widgets = { tasks: string[]; alerts: string[] };

type TaskCardData = {
    count: number;
    overdue?: number;
    waiting?: number;
    inbox?: number;
    items: TaskRow[];
};
type TaskCards = Record<string, TaskCardData | undefined>;

/** Real counts and the next rows of «مهامي» / «صندوق القسم», scoped by the server like the task lists. */
function TaskCardBody({ id, data }: { id: string; data: TaskCardData }) {
    const t = useT();
    const due = useDueLabel();
    const view = id === 'my_tasks' ? 'mine' : 'inbox';

    return (
        <div
            className="mt-3 flex flex-col gap-3"
            data-test={`home-widget-${id}-body`}
        >
            <p className="text-title text-strong">
                <span data-test="count">{data.count}</span>
                {id === 'my_tasks' && (data.overdue ?? 0) > 0 && (
                    <span className="ms-3 text-body font-semibold text-danger-text">
                        {t('tasks.home.overdue', { count: data.overdue ?? 0 })}
                    </span>
                )}
            </p>
            {id === 'my_tasks' && (data.inbox ?? 0) > 0 && (
                <p className="text-caption text-weak">
                    {t('tasks.home.inbox_hint', { count: data.inbox ?? 0 })}
                </p>
            )}
            {data.items.length > 0 && (
                <ul className="divide-y divide-stroke-weak">
                    {data.items.map((task) => (
                        <li
                            key={task.id}
                            className="flex items-center justify-between gap-3 py-2"
                        >
                            <Link
                                href={TaskController.show(task.id)}
                                className="min-w-0 truncate text-link hover:underline"
                            >
                                {task.title}
                            </Link>
                            <span
                                className={
                                    task.overdue
                                        ? 'shrink-0 text-caption font-semibold text-danger-text'
                                        : 'shrink-0 text-caption text-weak'
                                }
                            >
                                {due(task)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <Link
                href={TaskController.index({ query: { view } })}
                className="text-link hover:underline"
            >
                {t('tasks.home.open_all')}
            </Link>
        </div>
    );
}

/**
 * Work-center frame (SHR-01). Each section lists the widgets this user's permissions allow; domains fill a
 * widget with data later, until then it shows a placeholder.
 */
function Section({
    id,
    title,
    description,
    icon,
    widgets,
    cards,
}: {
    id: 'tasks' | 'alerts';
    title: string;
    description: string;
    icon: typeof ListChecks;
    widgets: string[];
    cards: TaskCards;
}) {
    const t = useT();

    return (
        <section
            aria-labelledby={`home-${id}`}
            className="flex flex-col gap-3"
            data-test={`home-${id}`}
        >
            <div>
                <h2 id={`home-${id}`} className="text-subtitle text-strong">
                    {title}
                </h2>
                <p className="text-body text-weak">{description}</p>
            </div>
            {widgets.length === 0 ? (
                <Card>
                    <EmptyState
                        compact
                        icon={icon}
                        title={t('home.section_empty')}
                    />
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {widgets.map((key) => (
                        <Card
                            key={key}
                            className="p-5"
                            data-test={`home-widget-${key}`}
                        >
                            <h3 className="text-control font-semibold text-strong">
                                {t(
                                    `home.widget.${key}.title` as TranslationKey,
                                )}
                            </h3>
                            <p className="mt-1 text-body text-weak">
                                {t(
                                    `home.widget.${key}.description` as TranslationKey,
                                )}
                            </p>
                            {cards[key] ? (
                                <TaskCardBody id={key} data={cards[key]} />
                            ) : (
                                <p className="mt-4 rounded-md bg-fill px-3 py-2 text-caption text-weak">
                                    {t('home.widget.placeholder')}
                                </p>
                            )}
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

export default function Dashboard({
    setup,
    widgets,
    taskCards,
    isExecutor,
    exec,
}: {
    setup: SetupSummary | null;
    widgets: Widgets;
    taskCards: TaskCards;
    isExecutor: boolean;
    exec: ExecHomeData | null;
}) {
    const t = useT();
    const { auth } = usePage().props;

    if (isExecutor && exec) {
        return (
            <>
                <Head title={t('dashboard.title')} />
                <ExecHome {...exec} name={auth.user.name} />
            </>
        );
    }

    return (
        <>
            <Head title={t('dashboard.title')} />
            <PageHeader
                title={t('dashboard.title')}
                description={t('dashboard.welcome', { name: auth.user.name })}
            />
            <div className="flex flex-col gap-8">
                {setup && <SetupChecklist setup={setup} />}
                <Section
                    id="tasks"
                    title={t('home.tasks.title')}
                    description={t('home.tasks.description')}
                    icon={ListChecks}
                    widgets={widgets.tasks}
                    cards={taskCards}
                />
                <Section
                    id="alerts"
                    title={t('home.alerts.title')}
                    description={t('home.alerts.description')}
                    icon={BellRing}
                    widgets={widgets.alerts}
                    cards={taskCards}
                />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'dashboard.title',
            href: dashboard(),
        },
    ],
};

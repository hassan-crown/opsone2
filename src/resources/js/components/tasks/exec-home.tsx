import { Link } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { EmptyState } from '@/components/opsone/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { TaskCard } from './task-card';
import type { TaskRow } from './types';

export type ExecHomeData = {
    today: TaskRow[];
    upcoming: TaskRow[];
    inbox: number;
    waiting: number;
};

/** ExecHomeMobile: a greeting, today's work with the visit time and map link, then what is coming. */
export function ExecHome({
    today,
    upcoming,
    inbox,
    waiting,
    name,
}: ExecHomeData & { name: string }) {
    const t = useT();

    return (
        <div
            className="mx-auto flex max-w-2xl flex-col gap-6"
            data-test="exec-home"
        >
            <div>
                <h1 className="text-title text-strong">
                    {t('exec.greeting', { name })}
                </h1>
                <p className="mt-1 text-body text-weak">
                    {today.length === 0
                        ? t('exec.today_none')
                        : t('exec.today_count', { count: today.length })}
                </p>
            </div>

            <section
                aria-labelledby="exec-today"
                className="flex flex-col gap-3"
            >
                <h2
                    id="exec-today"
                    className="text-control font-semibold text-strong"
                >
                    {t('exec.today')}
                </h2>
                {today.length === 0 ? (
                    <Card>
                        <EmptyState
                            compact
                            icon={ListChecks}
                            title={t('exec.today_none')}
                            description={
                                upcoming[0]
                                    ? t('exec.next', {
                                          task: upcoming[0].title,
                                      })
                                    : undefined
                            }
                        />
                    </Card>
                ) : (
                    <ul className="grid gap-3">
                        {today.map((task) => (
                            <TaskCard key={task.id} task={task} />
                        ))}
                    </ul>
                )}
            </section>

            {upcoming.length > 0 && (
                <section
                    aria-labelledby="exec-upcoming"
                    className="flex flex-col gap-3"
                >
                    <h2
                        id="exec-upcoming"
                        className="text-control font-semibold text-strong"
                    >
                        {t('exec.upcoming')}
                    </h2>
                    <ul className="grid gap-3">
                        {upcoming.map((task) => (
                            <TaskCard key={task.id} task={task} />
                        ))}
                    </ul>
                </section>
            )}

            {(inbox > 0 || waiting > 0) && (
                <div className="flex flex-wrap gap-2">
                    {inbox > 0 && (
                        <Button asChild variant="secondary">
                            <Link
                                href={TaskController.index({
                                    query: { view: 'inbox' },
                                })}
                            >
                                {t('exec.inbox', { count: inbox })}
                            </Link>
                        </Button>
                    )}
                    {waiting > 0 && (
                        <Button asChild variant="secondary">
                            <Link
                                href={TaskController.index({
                                    query: { view: 'waiting' },
                                })}
                            >
                                {t('exec.waiting', { count: waiting })}
                            </Link>
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

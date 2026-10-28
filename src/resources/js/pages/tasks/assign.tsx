import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { TaskStatusBadge, useDueLabel } from '@/components/tasks/status';
import type { TaskRow } from '@/components/tasks/types';
import { useTaskCall } from '@/components/tasks/use-task-call';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = {
    task: TaskRow;
    eligible: { user_id: number; name: string; open_tasks: number }[];
    departments: { id: number; name: string }[];
    mode: 'assign' | 'reassign';
};

/** TaskAssign: eligible members with their open work count — no automatic suggestion (WF-07). Also reassignment (P-23). */
export default function TaskAssign({
    task,
    eligible,
    departments,
    mode,
}: Props) {
    const t = useT();
    const due = useDueLabel();
    const { call, busy } = useTaskCall();
    const [pick, setPick] = useState<string>('');
    const [department, setDepartment] = useState(
        String(task.department?.id ?? ''),
    );
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const reassign = mode === 'reassign';

    const done = () => router.visit(TaskController.show.url(task.id));
    const submit = async () => {
        const body = reassign
            ? {
                  assignee_id: Number(pick),
                  reason,
                  lock_version: task.lock_version,
              }
            : { assignee_id: Number(pick), lock_version: task.lock_version };
        const result = await call(
            reassign ? 'reassign' : 'assign',
            task.id,
            body,
            { onDone: done },
        );
        setErrors(result.errors);
    };
    const route = async () => {
        const result = await call(
            'assign',
            task.id,
            {
                department_id: Number(department),
                lock_version: task.lock_version,
            },
            { onDone: done },
        );
        setErrors(result.errors);
    };
    const first = (k: string) => errors[k]?.[0];

    return (
        <>
            <Head
                title={t(
                    reassign ? 'tasks.reassign.title' : 'tasks.assign.title',
                    { task: task.title },
                )}
            />
            <div className="mx-auto max-w-2xl" data-test="task-assign">
                <PageHeader
                    title={t(
                        reassign
                            ? 'tasks.reassign.title'
                            : 'tasks.assign.title',
                        { task: task.title },
                    )}
                    description={t('tasks.assign.hint')}
                />
                <Card className="mb-4 gap-2 p-5">
                    <dl className="grid grid-cols-2 gap-2 text-body">
                        <dt className="text-weak">{t('tasks.col.order')}</dt>
                        <dd>
                            <bdi dir="ltr">{task.order.number}</bdi> ·{' '}
                            {task.order.customer}
                        </dd>
                        <dt className="text-weak">{t('tasks.col.stage')}</dt>
                        <dd>{task.stage}</dd>
                        <dt className="text-weak">{t('tasks.col.due')}</dt>
                        <dd>{due(task)}</dd>
                        <dt className="text-weak">{t('tasks.col.status')}</dt>
                        <dd>
                            <TaskStatusBadge status={task.status} />
                        </dd>
                    </dl>
                </Card>

                <Card className="mb-4 gap-3 p-5">
                    <h2 className="text-control font-semibold text-strong">
                        {t('tasks.assign.eligible')}
                    </h2>
                    {eligible.length === 0 ? (
                        <Alert variant="warning">
                            <AlertBody>{t('tasks.assign.none')}</AlertBody>
                        </Alert>
                    ) : (
                        <fieldset className="grid gap-2">
                            <legend className="sr-only">
                                {t('tasks.assign.eligible')}
                            </legend>
                            {eligible.map((m) => (
                                <label
                                    key={m.user_id}
                                    className="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border border-stroke-weak px-3 py-2 has-checked:border-brand has-checked:bg-brand-subtle"
                                >
                                    <input
                                        type="radio"
                                        name="assignee"
                                        value={m.user_id}
                                        checked={pick === String(m.user_id)}
                                        onChange={() =>
                                            setPick(String(m.user_id))
                                        }
                                        disabled={
                                            reassign &&
                                            m.user_id === task.assignee?.id
                                        }
                                    />
                                    <span className="flex-1 font-semibold">
                                        {m.name}
                                    </span>
                                    <span className="text-caption text-weak">
                                        {t('tasks.assign.open_tasks', {
                                            count: m.open_tasks,
                                        })}
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    {first('assignee_id') && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{first('assignee_id')}</AlertBody>
                        </Alert>
                    )}
                    {reassign && (
                        <TextareaField
                            label={t('tasks.reason')}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={1000}
                            error={first('reason')}
                            required
                        />
                    )}
                    <p className="text-caption text-weak">
                        {t('tasks.assign.effect')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="secondary">
                            <Link href={TaskController.show(task.id)}>
                                {t('ui.cancel')}
                            </Link>
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                busy ||
                                pick === '' ||
                                (reassign && reason.trim() === '')
                            }
                            onClick={submit}
                            data-test="assign-save"
                        >
                            {t(
                                reassign
                                    ? 'tasks.reassign.save'
                                    : 'tasks.assign.save',
                            )}
                        </Button>
                    </div>
                </Card>

                {!reassign && departments.length > 0 && (
                    <Card className="gap-3 p-5">
                        <h2 className="text-control font-semibold text-strong">
                            {t('tasks.assign.route')}
                        </h2>
                        <SelectField
                            label={t('tasks.assign.department')}
                            value={department}
                            onChange={(e) => setDepartment(e.target.value)}
                            options={departments.map((d) => ({
                                value: String(d.id),
                                label: d.name,
                            }))}
                            placeholder={t('tasks.assign.department_pick')}
                            error={first('department_id')}
                        />
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={busy || department === ''}
                                onClick={route}
                                data-test="route-save"
                            >
                                {t('tasks.assign.route_save')}
                            </Button>
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

TaskAssign.layout = {
    breadcrumbs: [{ title: 'tasks.nav', href: TaskController.index() }],
};

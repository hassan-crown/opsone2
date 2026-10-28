import { Head, Link, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import TaskMeasurementController from '@/actions/App/Http/Controllers/Tasks/TaskMeasurementController';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import type { Measurement, TaskRow } from '@/components/tasks/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    task: TaskRow;
    measurements: Measurement[];
    kind: 'estimated' | 'final';
    units: string[];
    today: string;
};

type Form = {
    location: string;
    name: string;
    value: string;
    unit: string;
    kind: string;
    measured_on: string;
    note: string;
};

/**
 * Measurement entry for the executor (Q-32, COM-07), mobile first: one measurement at a time with big controls,
 * the list below, and a way back to complete the task. Recorded through the assigned measurement task.
 */
export default function TaskMeasure({
    task,
    measurements,
    kind,
    units,
    today,
}: Props) {
    const t = useT();
    const blank: Form = {
        location: '',
        name: '',
        value: '',
        unit: 'cm',
        kind,
        measured_on: today,
        note: '',
    };
    const [form, setForm] = useState<Form>(blank);
    const [editing, setEditing] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<Form>) => setForm((f) => ({ ...f, ...patch }));
    const first = (k: string) => errors[k]?.[0];
    const finals = measurements.filter((m) => m.kind === 'final').length;

    const save = async () => {
        if (busy) {
            return;
        }
        setBusy(true);
        setErrors({});
        const url =
            editing === null
                ? TaskMeasurementController.store.url(task.id)
                : TaskMeasurementController.update.url({
                      task: task.id,
                      measurement: editing,
                  });
        const result = await api(editing === null ? 'POST' : 'PUT', url, {
            ...form,
            note: form.note || null,
        });
        setBusy(false);

        if (result.ok) {
            notify('success', { title: t('tasks.measure.saved') });
            setForm({
                ...blank,
                location: form.location,
                unit: form.unit,
                kind: form.kind,
            });
            setEditing(null);
            router.reload({ only: ['measurements'] });

            return;
        }
        if (result.status === 422) {
            setErrors(result.errors);

            return;
        }
        notify('danger', { title: result.message ?? t('tasks.error.failed') });
    };
    const edit = (m: Measurement) => {
        setEditing(m.id);
        setForm({
            location: m.location,
            name: m.name,
            value: m.value,
            unit: m.unit,
            kind: m.kind,
            measured_on: m.measured_on,
            note: m.note ?? '',
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <>
            <Head title={t('tasks.measure.title')} />
            <div
                className="mx-auto flex max-w-2xl flex-col gap-4"
                data-test="task-measure"
            >
                <div>
                    <h1 className="text-title text-strong">
                        {t('tasks.measure.title')}
                    </h1>
                    <p className="mt-1 text-body text-weak">
                        {task.title} · <bdi dir="ltr">{task.order.number}</bdi>
                    </p>
                </div>

                <Card className="gap-4 p-5">
                    <h2 className="text-control font-semibold text-strong">
                        {t(
                            editing === null
                                ? 'tasks.measure.add'
                                : 'tasks.measure.correct',
                        )}
                    </h2>
                    <TextField
                        label={t('tasks.measure.location')}
                        value={form.location}
                        onChange={(e) => set({ location: e.target.value })}
                        maxLength={120}
                        error={first('location')}
                        required
                    />
                    <TextField
                        label={t('tasks.measure.name')}
                        value={form.name}
                        onChange={(e) => set({ name: e.target.value })}
                        maxLength={120}
                        error={first('name')}
                        required
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <TextField
                            label={t('tasks.measure.value')}
                            value={form.value}
                            inputMode="decimal"
                            dir="ltr"
                            onChange={(e) => set({ value: e.target.value })}
                            error={first('value')}
                            hint={t('tasks.measure.value_hint')}
                            required
                        />
                        <SelectField
                            label={t('tasks.measure.unit')}
                            value={form.unit}
                            onChange={(e) => set({ unit: e.target.value })}
                            options={units.map((u) => ({
                                value: u,
                                label: t(
                                    `tasks.measure.unit.${u}` as TranslationKey,
                                ),
                            }))}
                            error={first('unit')}
                            required
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <SelectField
                            label={t('tasks.measure.kind')}
                            value={form.kind}
                            onChange={(e) => set({ kind: e.target.value })}
                            options={[
                                {
                                    value: 'estimated',
                                    label: t('tasks.measure.kind.estimated'),
                                },
                                {
                                    value: 'final',
                                    label: t('tasks.measure.kind.final'),
                                },
                            ]}
                            error={first('kind')}
                            required
                        />
                        <TextField
                            type="date"
                            label={t('tasks.measure.date')}
                            value={form.measured_on}
                            max={today}
                            onChange={(e) =>
                                set({ measured_on: e.target.value })
                            }
                            error={first('measured_on')}
                            required
                        />
                    </div>
                    <TextareaField
                        label={t('tasks.measure.note')}
                        value={form.note}
                        onChange={(e) => set({ note: e.target.value })}
                        maxLength={1000}
                        error={first('note')}
                        optional
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={save}
                            className="max-md:flex-1"
                            data-test="measure-save"
                        >
                            {t(
                                editing === null
                                    ? 'tasks.measure.save'
                                    : 'tasks.measure.save_correction',
                            )}
                        </Button>
                        {editing !== null && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => {
                                    setEditing(null);
                                    setForm(blank);
                                }}
                            >
                                {t('ui.cancel')}
                            </Button>
                        )}
                    </div>
                </Card>

                <Card className="gap-3 p-5" data-test="measure-list">
                    <h2 className="text-control font-semibold text-strong">
                        {t('tasks.measure.recorded', {
                            count: measurements.length,
                        })}
                    </h2>
                    {measurements.length === 0 ? (
                        <p className="text-body text-weak">
                            {t('tasks.measure.none')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-stroke-weak">
                            {measurements.map((m) => (
                                <li
                                    key={m.id}
                                    className="flex items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold">
                                            {m.location} — {m.name}
                                        </p>
                                        <p className="text-body text-weak">
                                            <bdi dir="ltr">
                                                {m.value}{' '}
                                                {t(
                                                    `tasks.measure.unit.${m.unit}` as TranslationKey,
                                                )}
                                            </bdi>{' '}
                                            <Badge
                                                variant={
                                                    m.kind === 'final'
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {t(
                                                    `tasks.measure.kind.${m.kind}` as TranslationKey,
                                                )}
                                            </Badge>
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={t('tasks.measure.correct')}
                                        onClick={() => edit(m)}
                                    >
                                        <Icon icon={Pencil} />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {finals === 0 &&
                        kind === 'final' &&
                        measurements.length > 0 && (
                            <p className="text-caption text-weak">
                                {t('tasks.measure.need_final')}
                            </p>
                        )}
                </Card>

                <Button asChild variant="secondary" block>
                    <Link href={TaskController.show(task.id)}>
                        {t('tasks.measure.back')}
                    </Link>
                </Button>
            </div>
        </>
    );
}

TaskMeasure.layout = {
    breadcrumbs: [{ title: 'tasks.nav', href: TaskController.index() }],
};

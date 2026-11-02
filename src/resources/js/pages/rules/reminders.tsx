import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ReminderSettingsController from '@/actions/App/Http/Controllers/Rules/ReminderSettingsController';
import Heading from '@/components/heading';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { reminders } from '@/routes/rules';
import { home } from '@/routes/settings';

type Settings = {
    before_days: number[];
    on_due: boolean;
    after_every_days: number;
    max_after: number;
    escalate_after_days: number | null;
    escalate_to: string | null;
    appointment_hours: number;
    lock_version: number | null;
};

type Props = {
    settings: Settings;
    roles: { key: string; name: string }[];
    hours: { from: string; until: string };
    canEdit: boolean;
};

/** Settings › reminders (ADR-0037): the collection reminder policy, escalation and the appointment lead time. */
export default function Reminders({ settings, roles, hours, canEdit }: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [before, setBefore] = useState(settings.before_days.join(', '));
    const [form, setForm] = useState(settings);

    const save = () => {
        const target = ReminderSettingsController.update();
        write.send('put', target.url, {
            ...form,
            before_days: before
                .split(/[,،\s]+/)
                .filter((d) => d !== '')
                .map(Number),
            escalate_to:
                form.escalate_after_days === null ? null : form.escalate_to,
        });
    };

    return (
        <>
            <Head title={t('rules.reminders.title')} />
            <PageHeader
                title={t('rules.reminders.title')}
                description={t('rules.reminders.description', {
                    from: hours.from,
                    until: hours.until,
                })}
            />
            <RulesNav current="reminders" canEdit={canEdit} />

            <fieldset
                disabled={!canEdit}
                className="m-0 max-w-2xl min-w-0 space-y-6 border-0 p-0"
                data-test="reminders-form"
            >
                <TextField
                    label={t('rules.reminders.before')}
                    hint={t('rules.reminders.before_hint')}
                    dir="ltr"
                    value={before}
                    error={
                        write.errors.before_days ??
                        write.errors['before_days.0']
                    }
                    onChange={(e) => setBefore(e.target.value)}
                />
                <CheckboxField
                    label={t('rules.reminders.on_due')}
                    checked={form.on_due}
                    onChange={(e) =>
                        setForm({ ...form, on_due: e.target.checked })
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t('rules.reminders.after_every')}
                        type="number"
                        dir="ltr"
                        min={1}
                        max={30}
                        value={String(form.after_every_days)}
                        error={write.errors.after_every_days}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                after_every_days: Number(e.target.value),
                            })
                        }
                    />
                    <TextField
                        label={t('rules.reminders.max_after')}
                        type="number"
                        dir="ltr"
                        min={0}
                        max={6}
                        value={String(form.max_after)}
                        error={write.errors.max_after}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                max_after: Number(e.target.value),
                            })
                        }
                    />
                </div>

                <Heading
                    variant="small"
                    title={t('rules.reminders.escalate')}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t('rules.reminders.escalate_after')}
                        type="number"
                        dir="ltr"
                        min={1}
                        max={90}
                        value={
                            form.escalate_after_days === null
                                ? ''
                                : String(form.escalate_after_days)
                        }
                        error={write.errors.escalate_after_days}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                escalate_after_days:
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                            })
                        }
                    />
                    <SelectField
                        label={t('rules.reminders.escalate_to')}
                        value={form.escalate_to ?? ''}
                        error={write.errors.escalate_to}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                escalate_to: e.target.value || null,
                            })
                        }
                        options={[
                            {
                                value: '',
                                label: t('rules.reminders.escalate_none'),
                            },
                            {
                                value: 'order_assignee',
                                label: t('rules.reminders.order_assignee'),
                            },
                            ...roles.map((r) => ({
                                value: r.key,
                                label: r.name,
                            })),
                        ]}
                    />
                </div>

                <Heading
                    variant="small"
                    title={t('rules.reminders.appointments')}
                />
                <TextField
                    label={t('rules.reminders.appointment_hours')}
                    type="number"
                    dir="ltr"
                    min={1}
                    max={168}
                    value={String(form.appointment_hours)}
                    error={write.errors.appointment_hours}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            appointment_hours: Number(e.target.value),
                        })
                    }
                />
                {canEdit && (
                    <Button
                        type="button"
                        onClick={save}
                        loading={write.busy}
                        data-test="save-reminders"
                    >
                        {t('rules.save')}
                    </Button>
                )}
            </fieldset>
        </>
    );
}

Reminders.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.reminders.title', href: reminders() },
    ],
};

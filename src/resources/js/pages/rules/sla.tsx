import { Head } from '@inertiajs/react';
import { useState } from 'react';
import TaskSlaController from '@/actions/App/Http/Controllers/Rules/TaskSlaController';
import Heading from '@/components/heading';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { sla as slaRoute } from '@/routes/rules';
import { home } from '@/routes/settings';

type Props = {
    settings: {
        enabled: boolean;
        default_days: number;
        by_type: Record<string, number>;
        holidays: string[];
        escalate_after_days: number;
    };
    types: { type: string; label: string }[];
    workingDays: number[];
    canEdit: boolean;
};

/** Settings › task SLA (ADR-0037): working days per kind of work, holidays and escalation. */
export default function Sla({ settings, types, workingDays, canEdit }: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [form, setForm] = useState({
        ...settings,
        by_type: { ...settings.by_type } as Record<string, number | null>,
    });

    return (
        <>
            <Head title={t('rules.sla.title')} />
            <PageHeader
                title={t('rules.sla.title')}
                description={t('rules.sla.description')}
            />
            <RulesNav current="sla" canEdit={canEdit} />
            <fieldset
                disabled={!canEdit}
                className="m-0 max-w-2xl min-w-0 space-y-6 border-0 p-0"
                data-test="sla-form"
            >
                <p className="text-caption text-weak">
                    {t('rules.sla.working_week', {
                        // The Saudi week reads from Sunday.
                        days: [...workingDays]
                            .sort((a, b) => (a % 7) - (b % 7))
                            .map((d) =>
                                t(
                                    `appointments.work.day.${d}` as TranslationKey,
                                ),
                            )
                            .join('، '),
                    })}
                </p>
                <CheckboxField
                    label={t('rules.sla.enabled')}
                    checked={form.enabled}
                    onChange={(e) =>
                        setForm({ ...form, enabled: e.target.checked })
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t('rules.sla.default')}
                        type="number"
                        dir="ltr"
                        min={0}
                        max={60}
                        value={String(form.default_days)}
                        error={write.errors.default_days}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                default_days: Number(e.target.value),
                            })
                        }
                    />
                    <TextField
                        label={t('rules.sla.escalate_after')}
                        type="number"
                        dir="ltr"
                        min={1}
                        max={30}
                        value={String(form.escalate_after_days)}
                        error={write.errors.escalate_after_days}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                escalate_after_days: Number(e.target.value),
                            })
                        }
                    />
                </div>
                <Heading variant="small" title={t('rules.sla.by_type')} />
                <div className="grid gap-4 sm:grid-cols-2">
                    {types.map((type) => (
                        <TextField
                            key={type.type}
                            label={type.label}
                            type="number"
                            dir="ltr"
                            min={0}
                            max={60}
                            placeholder={t('rules.sla.inherit')}
                            value={
                                form.by_type[type.type] == null
                                    ? ''
                                    : String(form.by_type[type.type])
                            }
                            error={write.errors[`by_type.${type.type}`]}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    by_type: {
                                        ...form.by_type,
                                        [type.type]:
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                    },
                                })
                            }
                        />
                    ))}
                </div>
                <Heading variant="small" title={t('rules.sla.holidays')} />
                <ul className="grid gap-2">
                    {form.holidays.map((day, i) => (
                        <li key={i} className="flex items-end gap-2">
                            <TextField
                                label={t('rules.sla.holidays')}
                                type="date"
                                dir="ltr"
                                value={day}
                                error={write.errors[`holidays.${i}`]}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        holidays: form.holidays.map((h, j) =>
                                            j === i ? e.target.value : h,
                                        ),
                                    })
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    setForm({
                                        ...form,
                                        holidays: form.holidays.filter(
                                            (_, j) => j !== i,
                                        ),
                                    })
                                }
                            >
                                {t('rules.remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
                <Button
                    type="button"
                    variant="secondary"
                    disabled={form.holidays.length >= 60}
                    onClick={() =>
                        setForm({ ...form, holidays: [...form.holidays, ''] })
                    }
                >
                    {t('rules.sla.add_holiday')}
                </Button>
                <p className="text-caption text-weak">{t('rules.sla.note')}</p>
                {canEdit && (
                    <Button
                        type="button"
                        loading={write.busy}
                        data-test="save-sla"
                        onClick={() =>
                            write.send('put', TaskSlaController.update().url, {
                                ...form,
                                holidays: form.holidays.filter((h) => h !== ''),
                            })
                        }
                    >
                        {t('rules.save')}
                    </Button>
                )}
            </fieldset>
        </>
    );
}

Sla.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.sla.title', href: slaRoute() },
    ],
};

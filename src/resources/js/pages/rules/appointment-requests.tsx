import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppointmentRequestSettingsController from '@/actions/App/Http/Controllers/AppointmentRequests/AppointmentRequestSettingsController';
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
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes/settings';
import type { FieldMode } from '@/types/appointment-requests';

type Settings = {
    site_enabled: boolean;
    portal_enabled: boolean;
    kinds: string[];
    fields: Record<string, FieldMode>;
    response_hours: number | null;
    lock_version: number | null;
};

type Props = {
    settings: Settings;
    allKinds: string[];
    fieldKeys: string[];
    canEdit: boolean;
};

const MODES: FieldMode[] = ['off', 'optional', 'required'];

/** Settings › appointment requests (ADR-0039): where customers may ask, which kinds, the form fields and the response time. */
export default function AppointmentRequestSettings({
    settings,
    allKinds,
    fieldKeys,
    canEdit,
}: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [form, setForm] = useState(settings);

    const toggleKind = (kind: string, on: boolean) =>
        setForm({
            ...form,
            kinds: on
                ? [...form.kinds, kind]
                : form.kinds.filter((k) => k !== kind),
        });

    const save = () =>
        write.send('put', AppointmentRequestSettingsController.update().url, {
            ...form,
        });

    return (
        <>
            <Head title={t('rules.appointment_requests.title')} />
            <PageHeader
                title={t('rules.appointment_requests.title')}
                description={t('rules.appointment_requests.description')}
            />
            <RulesNav current="appointment_requests" canEdit={canEdit} />

            <fieldset
                disabled={!canEdit}
                className="m-0 max-w-2xl min-w-0 space-y-6 border-0 p-0"
                data-test="appointment-request-settings"
            >
                <Heading
                    variant="small"
                    title={t('rules.appointment_requests.where')}
                />
                <CheckboxField
                    label={t('rules.appointment_requests.site_enabled')}
                    checked={form.site_enabled}
                    onChange={(e) =>
                        setForm({ ...form, site_enabled: e.target.checked })
                    }
                />
                <CheckboxField
                    label={t('rules.appointment_requests.portal_enabled')}
                    checked={form.portal_enabled}
                    onChange={(e) =>
                        setForm({ ...form, portal_enabled: e.target.checked })
                    }
                />

                <Heading
                    variant="small"
                    title={t('rules.appointment_requests.kinds')}
                    description={t('rules.appointment_requests.kinds_hint')}
                />
                <div className="grid gap-x-4 sm:grid-cols-2">
                    {allKinds.map((kind) => (
                        <CheckboxField
                            key={kind}
                            label={t(
                                `appointments.kind.${kind}` as TranslationKey,
                            )}
                            checked={form.kinds.includes(kind)}
                            onChange={(e) => toggleKind(kind, e.target.checked)}
                        />
                    ))}
                </div>
                {(write.errors.kinds ?? write.errors['kinds.0']) && (
                    <p className="text-caption text-danger-text">
                        {write.errors.kinds ?? write.errors['kinds.0']}
                    </p>
                )}

                <Heading
                    variant="small"
                    title={t('rules.appointment_requests.fields')}
                    description={t('rules.appointment_requests.fields_hint')}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    {fieldKeys.map((field) => (
                        <SelectField
                            key={field}
                            label={t(
                                `appointment_requests.field.${field}` as TranslationKey,
                            )}
                            value={form.fields[field]}
                            error={write.errors[`fields.${field}`]}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    fields: {
                                        ...form.fields,
                                        [field]: e.target.value as FieldMode,
                                    },
                                })
                            }
                            options={MODES.map((mode) => ({
                                value: mode,
                                label: t(
                                    `rules.appointment_requests.mode.${mode}` as TranslationKey,
                                ),
                            }))}
                        />
                    ))}
                </div>

                <TextField
                    label={t('rules.appointment_requests.response_hours')}
                    hint={t('rules.appointment_requests.response_hours_hint')}
                    type="number"
                    dir="ltr"
                    min={1}
                    max={168}
                    value={
                        form.response_hours === null
                            ? ''
                            : String(form.response_hours)
                    }
                    error={write.errors.response_hours}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            response_hours:
                                e.target.value === ''
                                    ? null
                                    : Number(e.target.value),
                        })
                    }
                />
                {canEdit && (
                    <Button
                        type="button"
                        onClick={save}
                        loading={write.busy}
                        data-test="save-appointment-request-settings"
                    >
                        {t('rules.save')}
                    </Button>
                )}
            </fieldset>
        </>
    );
}

AppointmentRequestSettings.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        {
            title: 'rules.appointment_requests.title',
            href: AppointmentRequestSettingsController.edit(),
        },
    ],
};

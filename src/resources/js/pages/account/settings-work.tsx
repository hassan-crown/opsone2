import { Form, Head } from '@inertiajs/react';
import WorkSettingsController from '@/actions/App/Http/Controllers/Account/WorkSettingsController';
import { useDurationLabel } from '@/components/calendar/shared';
import Heading from '@/components/heading';
import {
    CheckboxField,
    RadioGroup,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home, work } from '@/routes/settings';

type Props = {
    settings: {
        working_days: number[];
        work_starts_at: string;
        work_ends_at: string;
        appointment_slot_minutes: number;
        reminder_from: string;
        reminder_until: string;
        conflicting_appointments: 'allow_with_reason' | 'block';
    };
    timezone: string;
    slotOptions: number[];
    canEdit: boolean;
};

/** ISO weekdays in the order the Saudi week reads: Sunday first. */
const DAYS = [7, 1, 2, 3, 4, 5, 6];

export default function SettingsWork({
    settings,
    timezone,
    slotOptions,
    canEdit,
}: Props) {
    const t = useT();
    const durationLabel = useDurationLabel();

    return (
        <>
            <Head title={t('appointments.work.title')} />
            <PageHeader
                title={t('appointments.work.title')}
                description={t('appointments.work.description')}
            />
            {!canEdit && (
                <Alert variant="info" className="mb-6 max-w-2xl">
                    <AlertBody>
                        <AlertDescription>
                            {t('appointments.work.view_only')}
                        </AlertDescription>
                    </AlertBody>
                </Alert>
            )}

            <Form
                {...WorkSettingsController.update.form()}
                options={{ preserveScroll: true }}
                className="max-w-2xl space-y-10"
                data-test="work-settings-form"
            >
                {({ processing, errors }) => (
                    <>
                        <fieldset
                            disabled={!canEdit}
                            className="m-0 min-w-0 space-y-6 border-0 p-0"
                        >
                            <p className="text-caption text-weak">
                                {t('appointments.work.timezone', {
                                    zone: timezone,
                                })}
                            </p>

                            <div>
                                <Heading
                                    variant="small"
                                    title={t('appointments.work.days')}
                                    description={t(
                                        'appointments.work.days_hint',
                                    )}
                                />
                                <div className="grid gap-x-4 sm:grid-cols-2">
                                    {DAYS.map((day) => (
                                        <CheckboxField
                                            key={day}
                                            name="working_days[]"
                                            value={day}
                                            defaultChecked={settings.working_days.includes(
                                                day,
                                            )}
                                            label={t(
                                                `appointments.work.day.${day}` as TranslationKey,
                                            )}
                                        />
                                    ))}
                                </div>
                                {(errors.working_days ||
                                    errors['working_days.0']) && (
                                    <Alert variant="danger" role="alert">
                                        <AlertBody>
                                            {errors.working_days ??
                                                errors['working_days.0']}
                                        </AlertBody>
                                    </Alert>
                                )}
                            </div>

                            <Heading
                                variant="small"
                                title={t('appointments.work.hours')}
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    label={t('appointments.work.starts_at')}
                                    name="work_starts_at"
                                    type="time"
                                    dir="ltr"
                                    defaultValue={settings.work_starts_at}
                                    required
                                    error={errors.work_starts_at}
                                />
                                <TextField
                                    label={t('appointments.work.ends_at')}
                                    name="work_ends_at"
                                    type="time"
                                    dir="ltr"
                                    defaultValue={settings.work_ends_at}
                                    required
                                    error={errors.work_ends_at}
                                />
                            </div>

                            <SelectField
                                label={t('appointments.work.slot')}
                                hint={t('appointments.work.slot_hint')}
                                name="appointment_slot_minutes"
                                defaultValue={String(
                                    settings.appointment_slot_minutes,
                                )}
                                required
                                options={slotOptions.map((m) => ({
                                    value: String(m),
                                    label: durationLabel(m),
                                }))}
                                error={errors.appointment_slot_minutes}
                            />

                            <Heading
                                variant="small"
                                title={t('appointments.work.reminders')}
                                description={t(
                                    'appointments.work.reminders_hint',
                                    { zone: timezone },
                                )}
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    label={t('appointments.work.reminder_from')}
                                    name="reminder_from"
                                    type="time"
                                    dir="ltr"
                                    defaultValue={settings.reminder_from}
                                    required
                                    error={errors.reminder_from}
                                />
                                <TextField
                                    label={t(
                                        'appointments.work.reminder_until',
                                    )}
                                    name="reminder_until"
                                    type="time"
                                    dir="ltr"
                                    defaultValue={settings.reminder_until}
                                    required
                                    error={errors.reminder_until}
                                />
                            </div>

                            <RadioGroup
                                legend={t('appointments.work.policy')}
                                name="conflicting_appointments"
                                defaultValue={settings.conflicting_appointments}
                                hint={t('appointments.work.policy_hint')}
                                options={(
                                    ['allow_with_reason', 'block'] as const
                                ).map((p) => ({
                                    value: p,
                                    label: t(
                                        `appointments.work.policy.${p}` as TranslationKey,
                                    ),
                                }))}
                                error={errors.conflicting_appointments}
                            />
                        </fieldset>

                        {canEdit && (
                            <Button
                                type="submit"
                                loading={processing}
                                data-test="save-work-settings"
                            >
                                {processing && <Spinner />}
                                {t('ui.save')}
                            </Button>
                        )}
                    </>
                )}
            </Form>
        </>
    );
}

SettingsWork.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'appointments.work.title', href: work() },
    ],
};

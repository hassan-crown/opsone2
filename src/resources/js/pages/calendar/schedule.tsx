import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppointmentController from '@/actions/App/Http/Controllers/Calendar/AppointmentController';
import CalendarController from '@/actions/App/Http/Controllers/Calendar/CalendarController';
import {
    ClashPanel,
    addMinutes,
    hasClash,
    useClashPreview,
    useDurationLabel,
} from '@/components/calendar/shared';
import {
    CheckboxField,
    RadioGroup,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    orders: { id: number; number: string; customer: string; branch: string }[];
    orderId: number | null;
    members: { id: number; name: string }[];
    slotMinutes: number;
    allowsConflicts: boolean;
    timezone: string;
    date: string | null;
};

const KINDS = ['measurement', 'installation', 'delivery', 'visit'] as const;
const METHODS = ['phone', 'in_person', 'message', 'other'] as const;
const DURATIONS = [30, 45, 60, 90, 120, 150, 180, 240, 360, 480];

export default function ApptSchedule({
    orders,
    orderId,
    members,
    slotMinutes,
    timezone,
    date,
}: Props) {
    const t = useT();
    const durationLabel = useDurationLabel();
    const durations = Array.from(new Set([...DURATIONS, slotMinutes])).sort(
        (a, b) => a - b,
    );

    const form = useForm({
        order_id: orderId !== null ? String(orderId) : '',
        kind: 'measurement',
        date: date ?? '',
        time: '09:00',
        duration: String(slotMinutes),
        address: '',
        latitude: '',
        longitude: '',
        member_ids: [] as string[],
        confirm_mode: 'propose',
        confirmation_method: 'phone',
        conflict_reason: '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;

    const startsAt = data.date ? `${data.date}T${data.time}` : '';
    const endsAt = startsAt ? addMinutes(startsAt, Number(data.duration)) : '';
    const preview = useClashPreview(data.member_ids, startsAt, endsAt);
    const clash = hasClash(preview);
    const confirming = data.confirm_mode === 'confirm';
    const blocked = confirming && clash && preview?.allows_conflicts === false;
    const needsReason =
        confirming &&
        ((clash && preview?.allows_conflicts === true) ||
            errors.conflict_condition === 'conflict_reason_required');

    const toggle = (id: string, on: boolean) =>
        setData(
            'member_ids',
            on
                ? [...data.member_ids, id]
                : data.member_ids.filter((m) => m !== id),
        );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            order_id: Number(d.order_id),
            member_ids: d.member_ids.map(Number),
            starts_at: startsAt,
            ends_at: endsAt,
            latitude: d.latitude || null,
            longitude: d.longitude || null,
            confirmation_method:
                d.confirm_mode === 'confirm' ? d.confirmation_method : null,
            conflict_reason:
                d.confirm_mode === 'confirm' && d.conflict_reason
                    ? d.conflict_reason
                    : null,
        }));
        form.post(AppointmentController.store.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('appointments.schedule.title')} />
            <PageHeader
                title={t('appointments.schedule.title')}
                description={t('appointments.schedule.description')}
                back={
                    <Link
                        href={CalendarController.index.url()}
                        className="inline-flex min-h-11 items-center gap-1 text-link hover:underline"
                    >
                        <Icon
                            icon={ArrowLeft}
                            size="sm"
                            className="rtl:rotate-180"
                        />
                        {t('appointments.back')}
                    </Link>
                }
            />

            <form
                onSubmit={submit}
                className="max-w-2xl space-y-6"
                data-test="schedule-form"
            >
                <SelectField
                    label={t('appointments.schedule.order')}
                    value={data.order_id}
                    onChange={(e) => setData('order_id', e.target.value)}
                    required
                    placeholder={t('appointments.schedule.order_placeholder')}
                    options={orders.map((o) => ({
                        value: String(o.id),
                        label: `${o.number} · ${o.customer} · ${o.branch}`,
                    }))}
                    error={errors.order_id}
                />
                <SelectField
                    label={t('appointments.schedule.kind')}
                    value={data.kind}
                    onChange={(e) => setData('kind', e.target.value)}
                    required
                    options={KINDS.map((k) => ({
                        value: k,
                        label: t(`appointments.kind.${k}` as TranslationKey),
                    }))}
                    error={errors.kind}
                />
                <div className="grid gap-4 sm:grid-cols-3">
                    <TextField
                        label={t('appointments.schedule.date')}
                        type="date"
                        dir="ltr"
                        value={data.date}
                        onChange={(e) => setData('date', e.target.value)}
                        required
                        error={errors.starts_at}
                    />
                    <TextField
                        label={t('appointments.schedule.time')}
                        type="time"
                        dir="ltr"
                        value={data.time}
                        onChange={(e) => setData('time', e.target.value)}
                        required
                        hint={timezone}
                    />
                    <SelectField
                        label={t('appointments.schedule.duration')}
                        value={data.duration}
                        onChange={(e) => setData('duration', e.target.value)}
                        options={durations.map((m) => ({
                            value: String(m),
                            label: durationLabel(m),
                        }))}
                        error={errors.ends_at}
                    />
                </div>
                <TextareaField
                    label={t('appointments.schedule.address')}
                    hint={t('appointments.schedule.address_hint')}
                    rows={2}
                    maxLength={500}
                    value={data.address}
                    onChange={(e) => setData('address', e.target.value)}
                    required
                    error={errors.address}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t('appointments.schedule.latitude')}
                        dir="ltr"
                        inputMode="decimal"
                        optional
                        value={data.latitude}
                        onChange={(e) => setData('latitude', e.target.value)}
                        error={errors.latitude}
                    />
                    <TextField
                        label={t('appointments.schedule.longitude')}
                        dir="ltr"
                        inputMode="decimal"
                        optional
                        value={data.longitude}
                        onChange={(e) => setData('longitude', e.target.value)}
                        error={errors.longitude}
                    />
                </div>

                <fieldset className="m-0 min-w-0 border-0 p-0">
                    <legend className="mb-1 text-label text-strong">
                        {t('appointments.schedule.members')}
                    </legend>
                    <p className="mb-2 text-caption text-weak">
                        {t('appointments.schedule.members_hint')}
                    </p>
                    <div className="grid gap-x-4 sm:grid-cols-2">
                        {members.map((m) => (
                            <CheckboxField
                                key={m.id}
                                label={m.name}
                                checked={data.member_ids.includes(String(m.id))}
                                onChange={(e) =>
                                    toggle(String(m.id), e.target.checked)
                                }
                            />
                        ))}
                    </div>
                    {(errors.member_ids || errors['member_ids.0']) && (
                        <Alert variant="danger" className="mt-2" role="alert">
                            <AlertBody>
                                {errors.member_ids ?? errors['member_ids.0']}
                            </AlertBody>
                        </Alert>
                    )}
                </fieldset>

                <ClashPanel preview={preview} proposalOnly={!confirming} />

                <RadioGroup
                    legend={t('appointments.schedule.confirm_mode')}
                    name="confirm_mode"
                    value={data.confirm_mode}
                    onChange={(v) => setData('confirm_mode', v)}
                    options={(['propose', 'send', 'confirm'] as const).map(
                        (m) => ({
                            value: m,
                            label: t(
                                `appointments.schedule.mode.${m}` as TranslationKey,
                            ),
                        }),
                    )}
                />
                {confirming && (
                    <SelectField
                        label={t('appointments.schedule.method')}
                        value={data.confirmation_method}
                        onChange={(e) =>
                            setData('confirmation_method', e.target.value)
                        }
                        options={METHODS.map((m) => ({
                            value: m,
                            label: t(
                                `appointments.method.${m}` as TranslationKey,
                            ),
                        }))}
                        error={errors.confirmation_method}
                    />
                )}
                {needsReason && (
                    <TextareaField
                        label={t('appointments.conflict.reason')}
                        hint={t('appointments.conflict.reason_hint')}
                        rows={2}
                        maxLength={1000}
                        value={data.conflict_reason}
                        onChange={(e) =>
                            setData('conflict_reason', e.target.value)
                        }
                        required
                        data-test="conflict-reason"
                        error={errors.conflict_reason}
                    />
                )}
                {errors.conflict && (
                    <Alert
                        variant="danger"
                        role="alert"
                        data-test="conflict-error"
                    >
                        <AlertBody>{errors.conflict}</AlertBody>
                    </Alert>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        loading={processing}
                        disabled={blocked}
                        data-test="save-appointment"
                    >
                        {processing && <Spinner />}
                        {t('appointments.schedule.submit')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={CalendarController.index.url()}>
                            {t('appointments.schedule.cancel')}
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

ApptSchedule.layout = {
    breadcrumbs: [
        { title: 'calendar.nav', href: CalendarController.index() },
        {
            title: 'appointments.schedule.title',
            href: CalendarController.create(),
        },
    ],
};

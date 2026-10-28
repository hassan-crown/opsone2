import { Head, Link, useForm, router } from '@inertiajs/react';
import { ArrowLeft, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppointmentController from '@/actions/App/Http/Controllers/Calendar/AppointmentController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import CalendarController from '@/actions/App/Http/Controllers/Calendar/CalendarController';
import {
    ClashPanel,
    MapLink,
    StatusBadge,
    addMinutes,
    formatDay,
    hasClash,
    useClashPreview,
} from '@/components/calendar/shared';
import type { AppointmentRow } from '@/components/calendar/shared';
import { ConflictAlert } from '@/components/orders/conflict';
import {
    CheckboxField,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Detail = AppointmentRow & {
    lock_version: number;
    confirmation_method: string | null;
    conflict_reason: string | null;
    outcome: string | null;
    cancel_reason: string | null;
    pending_change: {
        starts_at: string;
        ends_at: string;
        reason: string;
    } | null;
};

type Props = {
    appointment: Detail;
    can: {
        send: boolean;
        confirm: boolean;
        requestChange: boolean;
        resolveChange: boolean;
        cancel: boolean;
        complete: boolean;
    };
    allowsConflicts: boolean;
    slotMinutes: number;
    timezone: string;
};

const METHODS = ['phone', 'in_person', 'message', 'other'] as const;
const splitLocal = (local: string) => ({
    date: local.slice(0, 10),
    time: local.slice(11, 16),
});

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[10rem_1fr] sm:gap-4">
            <dt className="text-label text-weak">{label}</dt>
            <dd className="m-0 min-w-0 text-strong">{children}</dd>
        </div>
    );
}

/** A dialog that posts one appointment action; refusals come back as form errors (reason, conflict, stale version). */
function ActionDialog({
    open,
    onOpenChange,
    title,
    description,
    url,
    initial,
    submitLabel,
    destructive,
    disabled,
    lockVersion,
    build,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    url: string;
    initial: Record<string, string | boolean>;
    submitLabel: string;
    destructive?: boolean;
    disabled?: boolean;
    lockVersion: number;
    build?: (data: Record<string, string | boolean>) => Record<string, unknown>;
    children: (ctx: {
        data: Record<string, string | boolean>;
        set: (key: string, value: string | boolean) => void;
        errors: Record<string, string>;
    }) => ReactNode;
}) {
    const t = useT();
    const form = useForm<Record<string, string | boolean>>(initial);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform(
            (d) =>
                ({
                    ...(build ? build(d) : d),
                    lock_version: lockVersion,
                }) as unknown as Record<string, string | boolean>,
        );
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription
                            className={description ? undefined : 'sr-only'}
                        >
                            {description ?? title}
                        </DialogDescription>
                    </DialogHeader>
                    {form.errors.lock_version && (
                        <ConflictAlert onReload={() => onOpenChange(false)} />
                    )}
                    {children({
                        data: form.data,
                        set: (k, v) => form.setData(k, v),
                        errors: form.errors as Record<string, string>,
                    })}
                    {form.errors.conflict && (
                        <Alert
                            variant="danger"
                            role="alert"
                            data-test="conflict-error"
                        >
                            <AlertBody>{form.errors.conflict}</AlertBody>
                        </Alert>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('appointments.action.close')}
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            loading={form.processing}
                            disabled={disabled}
                            data-test="action-submit"
                        >
                            {form.processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ApptChange({
    appointment: a,
    can,
    allowsConflicts,
    slotMinutes,
    timezone,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [dialog, setDialog] = useState<
        | null
        | 'confirm'
        | 'change'
        | 'approve'
        | 'discard'
        | 'cancel'
        | 'complete'
    >(null);
    const close = (open: boolean) => !open && setDialog(null);
    const url = (
        name:
            | 'send'
            | 'confirm'
            | 'requestChange'
            | 'approveChange'
            | 'discardChange'
            | 'cancel'
            | 'complete',
    ) => AppointmentController[name].url(a.id);
    const base = { lock_version: a.lock_version };

    const memberIds = a.members.map((m) => String(m.id));
    const length = Math.max(
        (new Date(`${a.ends_at}:00Z`).getTime() -
            new Date(`${a.starts_at}:00Z`).getTime()) /
            60000,
        slotMinutes,
    );
    const pending = a.pending_change;

    // Clash preview of the time being confirmed / approved.
    const approveStart = pending?.starts_at ?? a.starts_at;
    const approveEnd = pending?.ends_at ?? a.ends_at;
    const confirmPreview = useClashPreview(
        memberIds,
        a.starts_at,
        a.ends_at,
        a.id,
    );
    const approvePreview = useClashPreview(
        memberIds,
        approveStart,
        approveEnd,
        a.id,
    );

    return (
        <>
            <Head
                title={t('appointments.detail.title', {
                    number: a.order.number,
                })}
            />
            <PageHeader
                title={t('appointments.detail.title', {
                    number: a.order.number,
                })}
                back={
                    <Link
                        href={CalendarController.index.url({
                            query: { date: a.date },
                        })}
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
                actions={
                    <>
                        {can.send && (
                            <Button
                                variant="secondary"
                                data-test="action-send"
                                onClick={() =>
                                    router.post(url('send'), base, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {t('appointments.action.send')}
                            </Button>
                        )}
                        {can.confirm && (
                            <Button
                                data-test="action-confirm"
                                onClick={() => setDialog('confirm')}
                            >
                                {t('appointments.action.confirm')}
                            </Button>
                        )}
                        {can.requestChange && (
                            <Button
                                variant="secondary"
                                data-test="action-change"
                                onClick={() => setDialog('change')}
                            >
                                {t('appointments.action.change')}
                            </Button>
                        )}
                        {can.complete && (
                            <Button
                                variant="secondary"
                                data-test="action-complete"
                                onClick={() => setDialog('complete')}
                            >
                                {t('appointments.action.complete')}
                            </Button>
                        )}
                        {can.cancel && (
                            <Button
                                variant="secondary"
                                data-test="action-cancel"
                                onClick={() => setDialog('cancel')}
                            >
                                {t('appointments.action.cancel')}
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid max-w-3xl gap-4">
                {a.conflicted && (
                    <Alert variant="warning" data-test="clash-flag">
                        <AlertBody>
                            <span className="inline-flex items-center gap-2">
                                <Icon icon={TriangleAlert} size="sm" />
                                {t('appointments.detail.clash_flag')}
                            </span>
                        </AlertBody>
                    </Alert>
                )}

                {pending && (
                    <Alert variant="info" data-test="pending-change">
                        <AlertBody>
                            <p className="m-0 font-semibold">
                                {t('appointments.detail.pending_title')}
                            </p>
                            <p className="m-0">
                                {t('appointments.detail.pending_body')}
                            </p>
                            <p className="m-0 mt-2">
                                <span className="text-weak">
                                    {t('appointments.detail.pending_time')}
                                    :{' '}
                                </span>
                                <bdi dir="ltr">
                                    {formatDay(
                                        pending.starts_at.slice(0, 10),
                                        locale,
                                        { day: 'numeric', month: 'long' },
                                    )}{' '}
                                    {pending.starts_at.slice(11)}–
                                    {pending.ends_at.slice(11)}
                                </bdi>
                            </p>
                            <p className="m-0">
                                <span className="text-weak">
                                    {t('appointments.detail.pending_reason')}
                                    :{' '}
                                </span>
                                {pending.reason}
                            </p>
                            <ClashPanel preview={approvePreview} />
                            {can.resolveChange && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button
                                        data-test="action-approve"
                                        onClick={() => setDialog('approve')}
                                    >
                                        {t('appointments.action.approve')}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        data-test="action-discard"
                                        onClick={() => setDialog('discard')}
                                    >
                                        {t('appointments.action.discard')}
                                    </Button>
                                </div>
                            )}
                        </AlertBody>
                    </Alert>
                )}

                <Card>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge status={a.status} />
                        {a.no_show && (
                            <span className="text-caption text-warning-text">
                                {t('appointments.no_show')}
                            </span>
                        )}
                    </div>
                    <dl className="m-0 grid gap-3">
                        <Row label={t('appointments.detail.order')}>
                            <Link
                                href={OrderController.show.url(a.order.id)}
                                className="font-mono text-link hover:underline"
                            >
                                <bdi dir="ltr">{a.order.number}</bdi>
                            </Link>
                            {' · '}
                            {t(`appointments.kind.${a.kind}` as TranslationKey)}
                        </Row>
                        {a.customer && (
                            <Row label={t('appointments.detail.customer')}>
                                {a.customer}
                            </Row>
                        )}
                        <Row label={t('appointments.detail.branch')}>
                            {a.branch}
                        </Row>
                        <Row label={t('appointments.detail.when')}>
                            {formatDay(a.date, locale, {
                                weekday: 'long',
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric',
                            })}{' '}
                            <bdi dir="ltr">
                                {a.start}–{a.end}
                            </bdi>
                            <span className="block text-caption text-weak">
                                {timezone}
                            </span>
                        </Row>
                        <Row label={t('appointments.detail.members')}>
                            {a.members.map((m) => m.name).join('، ')}
                        </Row>
                        <Row label={t('appointments.detail.address')}>
                            {a.address}
                            <MapLink url={a.maps_url} className="mt-1 flex" />
                        </Row>
                        {a.confirmation_method && (
                            <Row label={t('appointments.detail.method')}>
                                {t(
                                    `appointments.method.${a.confirmation_method}` as TranslationKey,
                                )}
                            </Row>
                        )}
                        {a.conflict_reason && (
                            <Row
                                label={t('appointments.detail.conflict_reason')}
                            >
                                {a.conflict_reason}
                            </Row>
                        )}
                        {a.outcome && (
                            <Row label={t('appointments.detail.outcome')}>
                                {a.outcome}
                            </Row>
                        )}
                        {a.cancel_reason && (
                            <Row label={t('appointments.detail.cancel_reason')}>
                                {a.cancel_reason}
                            </Row>
                        )}
                    </dl>
                </Card>
            </div>

            {/* Confirm: records the customer's confirmation; the clash policy applies. */}
            <ActionDialog
                open={dialog === 'confirm'}
                onOpenChange={close}
                title={t('appointments.action.confirm')}
                lockVersion={a.lock_version}
                url={url('confirm')}
                initial={{ confirmation_method: 'phone', conflict_reason: '' }}
                submitLabel={t('appointments.action.confirm')}
                disabled={hasClash(confirmPreview) && !allowsConflicts}
                build={(d) => ({
                    ...d,
                    conflict_reason: d.conflict_reason || null,
                })}
            >
                {({ data, set, errors }) => (
                    <>
                        <SelectField
                            label={t('appointments.schedule.method')}
                            value={String(data.confirmation_method)}
                            onChange={(e) =>
                                set('confirmation_method', e.target.value)
                            }
                            options={METHODS.map((m) => ({
                                value: m,
                                label: t(
                                    `appointments.method.${m}` as TranslationKey,
                                ),
                            }))}
                            error={errors.confirmation_method}
                        />
                        <ClashPanel preview={confirmPreview} />
                        {(errors.conflict_condition ===
                            'conflict_reason_required' ||
                            (hasClash(confirmPreview) && allowsConflicts)) && (
                            <TextareaField
                                label={t('appointments.conflict.reason')}
                                hint={t('appointments.conflict.reason_hint')}
                                rows={2}
                                maxLength={1000}
                                required
                                value={String(data.conflict_reason)}
                                onChange={(e) =>
                                    set('conflict_reason', e.target.value)
                                }
                                error={errors.conflict_reason}
                            />
                        )}
                    </>
                )}
            </ActionDialog>

            {/* ApptChange: propose another time; the original stays booked until approved. */}
            <ChangeDialog
                open={dialog === 'change'}
                onOpenChange={close}
                url={url('requestChange')}
                appointment={a}
                memberIds={memberIds}
                length={length}
            />

            <ActionDialog
                open={dialog === 'approve'}
                onOpenChange={close}
                title={t('appointments.action.approve')}
                lockVersion={a.lock_version}
                url={url('approveChange')}
                initial={{ conflict_reason: '' }}
                submitLabel={t('appointments.action.approve')}
                disabled={hasClash(approvePreview) && !allowsConflicts}
                build={(d) => ({
                    ...d,
                    conflict_reason: d.conflict_reason || null,
                })}
            >
                {({ data, set, errors }) => (
                    <>
                        <ClashPanel preview={approvePreview} />
                        {(errors.conflict_condition ===
                            'conflict_reason_required' ||
                            (hasClash(approvePreview) && allowsConflicts)) && (
                            <TextareaField
                                label={t('appointments.conflict.reason')}
                                hint={t('appointments.conflict.reason_hint')}
                                rows={2}
                                maxLength={1000}
                                required
                                value={String(data.conflict_reason)}
                                onChange={(e) =>
                                    set('conflict_reason', e.target.value)
                                }
                                error={errors.conflict_reason}
                            />
                        )}
                    </>
                )}
            </ActionDialog>

            <ActionDialog
                open={dialog === 'discard'}
                onOpenChange={close}
                title={t('appointments.action.discard')}
                lockVersion={a.lock_version}
                url={url('discardChange')}
                initial={{ reason: '' }}
                submitLabel={t('appointments.action.discard')}
            >
                {({ data, set, errors }) => (
                    <TextareaField
                        label={t('appointments.discard.reason')}
                        rows={3}
                        maxLength={1000}
                        required
                        value={String(data.reason)}
                        onChange={(e) => set('reason', e.target.value)}
                        error={errors.reason}
                    />
                )}
            </ActionDialog>

            <ActionDialog
                open={dialog === 'cancel'}
                onOpenChange={close}
                title={t('appointments.cancel.title')}
                description={t('appointments.cancel.body')}
                lockVersion={a.lock_version}
                url={url('cancel')}
                initial={{ reason: '' }}
                submitLabel={t('appointments.action.confirm_cancel')}
                destructive
            >
                {({ data, set, errors }) => (
                    <TextareaField
                        label={t('appointments.cancel.reason')}
                        rows={3}
                        maxLength={1000}
                        required
                        value={String(data.reason)}
                        onChange={(e) => set('reason', e.target.value)}
                        error={errors.reason}
                    />
                )}
            </ActionDialog>

            <ActionDialog
                open={dialog === 'complete'}
                onOpenChange={close}
                title={t('appointments.complete.title')}
                lockVersion={a.lock_version}
                url={url('complete')}
                initial={{ outcome: '', no_show: false }}
                submitLabel={t('appointments.action.complete')}
            >
                {({ data, set, errors }) => (
                    <>
                        <TextareaField
                            label={t('appointments.complete.outcome')}
                            rows={3}
                            maxLength={1000}
                            required
                            value={String(data.outcome)}
                            onChange={(e) => set('outcome', e.target.value)}
                            error={errors.outcome}
                        />
                        <CheckboxField
                            label={t('appointments.complete.no_show')}
                            checked={Boolean(data.no_show)}
                            onChange={(e) => set('no_show', e.target.checked)}
                        />
                    </>
                )}
            </ActionDialog>
        </>
    );
}

function ChangeDialog({
    open,
    onOpenChange,
    url,
    appointment: a,
    memberIds,
    length,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    appointment: Detail;
    memberIds: string[];
    length: number;
}) {
    const t = useT();
    const initial = splitLocal(a.starts_at);
    const [date, setDate] = useState(initial.date);
    const [time, setTime] = useState(initial.time);
    const startsAt = date ? `${date}T${time}` : '';
    const endsAt = startsAt ? addMinutes(startsAt, length) : '';
    const preview = useClashPreview(
        memberIds,
        open ? startsAt : '',
        endsAt,
        a.id,
    );

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('appointments.change.title')}
            description={t('appointments.change.body')}
            url={url}
            lockVersion={a.lock_version}
            initial={{ reason: '' }}
            submitLabel={t('appointments.action.submit_change')}
            build={(d) => ({ ...d, starts_at: startsAt, ends_at: endsAt })}
        >
            {({ data, set, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={t('appointments.change.date')}
                            type="date"
                            dir="ltr"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            required
                            error={errors.starts_at}
                        />
                        <TextField
                            label={t('appointments.change.time')}
                            type="time"
                            dir="ltr"
                            value={time}
                            onChange={(e) => setTime(e.target.value)}
                            required
                            error={errors.ends_at}
                        />
                    </div>
                    <ClashPanel preview={preview} proposalOnly />
                    <TextareaField
                        label={t('appointments.change.reason')}
                        rows={3}
                        maxLength={1000}
                        required
                        value={String(data.reason)}
                        onChange={(e) => set('reason', e.target.value)}
                        error={errors.reason}
                    />
                </>
            )}
        </ActionDialog>
    );
}

ApptChange.layout = {
    breadcrumbs: [{ title: 'calendar.nav', href: CalendarController.index() }],
};

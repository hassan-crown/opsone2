import { Form, Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarPlus,
    CircleX,
    LifeBuoy,
    MapPin,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { TextareaField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import {
    DateText,
    FileLinks,
    Forward,
    Money,
    Section,
    StatusBadge,
} from '@/components/portal/parts';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { BankTransferCard } from '@/components/portal/bank-transfer';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { create as newAppointmentRequest } from '@/routes/customer/appointment-requests';
import { change, confirm } from '@/routes/customer/appointments';
import { withdraw as withdrawCancellation } from '@/routes/customer/cancellations';
import { show as showDocument } from '@/routes/customer/documents';
import { cancel, index as ordersIndex, pay } from '@/routes/customer/orders';
import { withdraw as withdrawProof } from '@/routes/customer/proofs';
import { create as newTicket } from '@/routes/customer/tickets';
import type {
    PortalAppointment,
    PortalBankDetails,
    PortalOrderDetail,
} from '@/types/portal';

type Stage = PortalOrderDetail['timeline'][number];

/**
 * Consecutive stages under one customer-facing name read as one step («قيد التصنيع» covers sending to the workshop and
 * receiving from it): started when the first started, done only when the last is done.
 */
function journey(timeline: Stage[]): Stage[] {
    return timeline.reduce<Stage[]>((steps, stage) => {
        const previous = steps[steps.length - 1];
        if (previous && stage.name && previous.name === stage.name) {
            steps[steps.length - 1] = {
                ...stage,
                started_on: previous.started_on ?? stage.started_on,
            };

            return steps;
        }

        return [...steps, stage];
    }, []);
}

/** CustOrder (WEB-09/10/11/13): journey, money, documents, appointments, receipts and the cancellation request. */
export default function PortalOrder({
    order,
    canRequestAppointment,
    bank,
}: {
    order: PortalOrderDetail;
    canRequestAppointment: boolean;
    bank: PortalBankDetails | null;
}) {
    const t = useT();
    const f = order.finance;
    const steps = journey(order.timeline);
    const cancellation =
        order.cancellation && order.cancellation.status !== 'withdrawn'
            ? order.cancellation
            : null;

    return (
        <>
            <Head title={order.number} />
            <Link
                href={ordersIndex().url}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                {t('portal.nav.orders')}
            </Link>
            <div className="mb-6 flex flex-wrap items-center gap-2">
                <h1 className="text-title text-strong">
                    <bdi dir="ltr">{order.number}</bdi>
                </h1>
                <StatusBadge scope="order" status={order.status} />
                <p className="w-full text-body text-weak">
                    {[
                        order.title,
                        t(`orders.kind.${order.kind}` as TranslationKey),
                    ]
                        .filter(Boolean)
                        .join(t('portal.sep'))}
                </p>
            </div>

            {f.due_now_minor > 0 && order.can.upload_proof && (
                <Card className="mb-8 border-warning-bg">
                    <p className="text-label text-strong">
                        {t('portal.order.due_now')}{' '}
                        <Money minor={f.due_now_minor} />
                    </p>
                    {f.pending_proofs_minor > 0 && (
                        <p className="text-body text-weak">
                            {t('portal.order.pending_note')}{' '}
                            <Money minor={f.pending_proofs_minor} />
                        </p>
                    )}
                    <Link
                        href={pay(order.id).url}
                        className={buttonVariants({ variant: 'default' })}
                    >
                        {t('portal.required.pay')}
                    </Link>
                </Card>
            )}

            {steps.length > 0 && (
                <Section title={t('portal.order.journey')}>
                    <ol className="flex flex-col gap-0">
                        {steps.map((stage, i) => (
                            <li key={i} className="flex gap-3">
                                <div className="flex flex-col items-center">
                                    <span
                                        className={cn(
                                            'mt-1 size-3 shrink-0 rounded-full border-2',
                                            stage.completed_on
                                                ? 'border-success-text bg-success-text'
                                                : stage.started_on
                                                  ? 'border-brand bg-brand'
                                                  : 'border-stroke-control bg-surface',
                                        )}
                                    />
                                    {i < steps.length - 1 && (
                                        <span className="w-px flex-1 bg-stroke-weak" />
                                    )}
                                </div>
                                <div className="pb-4">
                                    <p
                                        className={cn(
                                            'text-label',
                                            stage.started_on &&
                                                !stage.completed_on
                                                ? 'text-strong'
                                                : 'text-weak',
                                        )}
                                    >
                                        {stage.name}
                                    </p>
                                    <p className="text-caption text-weak">
                                        {stage.completed_on ? (
                                            <>
                                                {t('portal.order.stage_done')}{' '}
                                                <DateText
                                                    value={stage.completed_on}
                                                />
                                            </>
                                        ) : stage.started_on ? (
                                            t('portal.order.stage_current')
                                        ) : (
                                            t('portal.order.stage_next')
                                        )}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </Section>
            )}

            {order.lines.length > 0 && (
                <Section title={t('portal.order.items')}>
                    <ul className="divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-card">
                        {order.lines.map((line, i) => (
                            <li
                                key={i}
                                className="flex justify-between gap-3 p-3 text-body"
                            >
                                <span
                                    dir="auto"
                                    className="min-w-0 text-strong"
                                >
                                    {line.description}
                                </span>
                                <bdi
                                    dir="ltr"
                                    className="shrink-0 whitespace-nowrap text-weak"
                                >
                                    {line.quantity} {line.unit ?? ''}
                                </bdi>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section title={t('portal.order.money')}>
                <Card>
                    <dl className="grid grid-cols-2 gap-3 text-body">
                        <dt className="text-weak">{t('portal.money.total')}</dt>
                        <dd className="text-end">
                            <Money minor={f.agreed_minor} />
                        </dd>
                        <dt className="text-weak">{t('portal.money.paid')}</dt>
                        <dd className="text-end">
                            <Money minor={f.collected_minor} />
                        </dd>
                        <dt className="text-weak">
                            {t('portal.money.due_now')}
                        </dt>
                        <dd className="text-end">
                            <Money minor={f.due_now_minor} />
                        </dd>
                        <dt className="text-weak">
                            {t('portal.money.remaining')}
                        </dt>
                        <dd className="text-end">
                            <Money minor={f.remaining_minor} />
                        </dd>
                    </dl>
                    {f.dues.length > 0 && (
                        <ul className="flex flex-col gap-2 border-t border-stroke-weak pt-3">
                            {f.dues.map((due) => (
                                <li
                                    key={due.id}
                                    className="flex flex-wrap items-center justify-between gap-2 text-body"
                                >
                                    <span className="text-strong">
                                        {due.name}
                                        {due.due_on && (
                                            <span className="ms-2 text-caption text-weak">
                                                <DateText value={due.due_on} />
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <Money minor={due.amount_minor} />
                                        <StatusBadge
                                            scope="due"
                                            status={due.status}
                                        />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {bank &&
                        order.can.upload_proof &&
                        (f.remaining_minor ?? 1) > 0 && (
                            <BankTransferCard
                                bank={bank}
                                amountMinor={
                                    f.due_now_minor > 0
                                        ? f.due_now_minor
                                        : f.remaining_minor
                                }
                                reference={order.number}
                            />
                        )}
                    {order.can.upload_proof && (
                        <Link
                            href={pay(order.id).url}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            {t('portal.order.send_receipt')}
                        </Link>
                    )}
                </Card>
            </Section>

            {order.documents.length > 0 && (
                <Section title={t('portal.nav.documents')}>
                    <div className="flex flex-col gap-2">
                        {order.documents.map((doc) => (
                            <Link
                                key={doc.id}
                                href={showDocument(doc.id).url}
                                className="flex items-center gap-3 rounded-lg border border-stroke-weak bg-card p-3 hover:bg-hover"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-label text-strong">
                                        {t(
                                            `portal.document.kind.${doc.kind}` as TranslationKey,
                                        )}{' '}
                                        <bdi dir="ltr" className="text-weak">
                                            {doc.number}
                                        </bdi>
                                    </p>
                                    <p className="text-caption text-weak">
                                        <DateText value={doc.sent_on} />
                                    </p>
                                </div>
                                <StatusBadge
                                    scope="document"
                                    status={doc.status}
                                />
                                <Forward />
                            </Link>
                        ))}
                    </div>
                </Section>
            )}

            {order.appointments.length > 0 && (
                <Section title={t('portal.order.appointments')}>
                    <div className="flex flex-col gap-3">
                        {order.appointments.map((appointment) => (
                            <AppointmentCard
                                key={appointment.id}
                                appointment={appointment}
                            />
                        ))}
                    </div>
                </Section>
            )}

            {order.proofs.length > 0 && (
                <Section title={t('portal.order.proofs')}>
                    <ul className="flex flex-col gap-2">
                        {order.proofs.map((proof) => (
                            <li
                                key={proof.id}
                                className="flex flex-col gap-2 rounded-lg border border-stroke-weak bg-card p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-label text-strong">
                                        <Money minor={proof.claimed_minor} />{' '}
                                        <span className="text-caption text-weak">
                                            <DateText value={proof.paid_on} />
                                        </span>
                                    </span>
                                    <StatusBadge
                                        scope="proof"
                                        status={proof.status}
                                    />
                                </div>
                                {proof.correction_reason && (
                                    <p className="text-body text-danger-text">
                                        {proof.correction_reason}
                                    </p>
                                )}
                                <FileLinks files={proof.files} />
                                <div className="flex flex-wrap gap-2">
                                    {proof.status === 'needs_correction' &&
                                        !proof.replaced && (
                                            <Link
                                                href={
                                                    pay(order.id, {
                                                        query: {
                                                            replaces: proof.id,
                                                        },
                                                    }).url
                                                }
                                                className={buttonVariants({
                                                    variant: 'default',
                                                    size: 'sm',
                                                })}
                                            >
                                                {t('portal.required.resend')}
                                            </Link>
                                        )}
                                    {[
                                        'under_review',
                                        'needs_correction',
                                    ].includes(proof.status) &&
                                        !proof.replaced && (
                                            <Form
                                                {...withdrawProof.form(
                                                    proof.id,
                                                )}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {t(
                                                            'portal.order.withdraw_proof',
                                                        )}
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {order.receipts.length > 0 && (
                <Section title={t('portal.order.receipts')}>
                    <ul className="divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-card">
                        {order.receipts.map((receipt) => (
                            <li
                                key={receipt.id}
                                className="flex items-center justify-between gap-2 p-3 text-body"
                            >
                                <span>
                                    <bdi dir="ltr" className="text-strong">
                                        {receipt.number}
                                    </bdi>{' '}
                                    <span className="text-caption text-weak">
                                        <DateText value={receipt.issued_on} />
                                    </span>
                                </span>
                                <Money minor={receipt.amount_minor} />
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {cancellation && (
                <Section title={t('portal.order.cancellation')}>
                    <Card>
                        <div className="flex flex-wrap items-center gap-2">
                            <bdi dir="ltr" className="text-label text-strong">
                                {cancellation.number}
                            </bdi>
                            <StatusBadge
                                scope="cancellation"
                                status={cancellation.status}
                            />
                        </div>
                        <p className="text-body text-weak">
                            {t(
                                `portal.cancel.status_note.${cancellation.status}` as TranslationKey,
                            )}
                        </p>
                        {cancellation.decision_reason && (
                            <p className="text-body text-strong">
                                {cancellation.decision_reason}
                            </p>
                        )}
                        {cancellation.can_withdraw && (
                            <Form
                                {...withdrawCancellation.form(cancellation.id)}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {t('portal.cancel.withdraw')}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </Card>
                </Section>
            )}

            <Section title={t('portal.order.actions')}>
                <ul className="divide-y divide-stroke-weak overflow-hidden rounded-lg border border-stroke-weak bg-card">
                    {canRequestAppointment && (
                        <ActionRow
                            href={
                                newAppointmentRequest({
                                    query: { order: order.id },
                                }).url
                            }
                            icon={CalendarPlus}
                            title={t('appointment_requests.portal.request')}
                            hint={t('appointment_requests.portal.lead')}
                            test="request-appointment"
                        />
                    )}
                    <ActionRow
                        href={newTicket({ query: { order: order.id } }).url}
                        icon={LifeBuoy}
                        title={t('tickets.report_issue')}
                        hint={t('portal.order.support_hint')}
                    />
                    {!cancellation && order.can.request_cancellation && (
                        <ActionRow
                            href={cancel(order.id).url}
                            icon={CircleX}
                            title={t('portal.cancel.request')}
                            danger
                        />
                    )}
                </ul>
                {!cancellation && !order.can.request_cancellation && (
                    <p className="mt-2 text-caption text-weak">
                        {t('portal.cancel.not_available')}
                    </p>
                )}
            </Section>
        </>
    );
}

function ActionRow({
    href,
    icon,
    title,
    hint,
    danger = false,
    test,
}: {
    href: string;
    icon: LucideIcon;
    title: string;
    hint?: string;
    danger?: boolean;
    test?: string;
}) {
    return (
        <li>
            <Link
                href={href}
                data-test={test}
                className="flex min-h-14 items-center gap-3 px-4 py-3 hover:bg-hover active:bg-fill"
            >
                <Icon
                    icon={icon}
                    className={danger ? 'text-danger-text' : 'text-weak'}
                />
                <span className="min-w-0 flex-1">
                    <span
                        className={cn(
                            'block text-label',
                            danger ? 'text-danger-text' : 'text-strong',
                        )}
                    >
                        {title}
                    </span>
                    {hint && (
                        <span className="mt-0.5 block text-caption text-weak">
                            {hint}
                        </span>
                    )}
                </span>
                <Forward />
            </Link>
        </li>
    );
}

function AppointmentCard({ appointment }: { appointment: PortalAppointment }) {
    const t = useT();
    const [changing, setChanging] = useState(false);

    return (
        <Card>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex items-center gap-2 text-label text-strong">
                    <Icon icon={CalendarClock} />
                    {t(
                        `portal.appointment.kind.${appointment.kind}` as TranslationKey,
                    )}
                </p>
                <StatusBadge scope="appointment" status={appointment.status} />
            </div>
            <p className="text-body text-strong">
                <DateText value={appointment.starts_at} time />
            </p>
            <a
                href={appointment.maps_url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1 text-body text-link underline-offset-4 hover:underline"
            >
                <Icon icon={MapPin} size="sm" />
                {appointment.address}
            </a>
            {appointment.proposed && (
                <p className="text-body text-weak">
                    {t('portal.appointment.proposed')}{' '}
                    <DateText value={appointment.proposed.starts_at} time />
                </p>
            )}
            {appointment.customer_request && (
                <p className="rounded-md bg-fill p-3 text-body text-weak">
                    {t('portal.appointment.requested')}{' '}
                    {appointment.customer_request.note}
                    {appointment.customer_request.preferred && (
                        <>
                            {t('portal.sep')}
                            <bdi>{appointment.customer_request.preferred}</bdi>
                        </>
                    )}
                </p>
            )}
            {(appointment.can.confirm || appointment.can.request_change) && (
                <div className="flex flex-wrap gap-2">
                    {appointment.can.confirm && (
                        <Form {...confirm.form(appointment.id)}>
                            {({ processing, errors }) => (
                                <>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('portal.appointment.confirm')}
                                    </Button>
                                    {(errors.appointment ??
                                        errors.transition) && (
                                        <p className="mt-2 text-caption text-danger-text">
                                            {errors.appointment ??
                                                errors.transition}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    )}
                    {appointment.can.request_change && !changing && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setChanging(true)}
                        >
                            {t('portal.appointment.change')}
                        </Button>
                    )}
                </div>
            )}
            {changing && (
                <Form
                    {...change.form(appointment.id)}
                    className="flex flex-col gap-3 border-t border-stroke-weak pt-3"
                    onSuccess={() => setChanging(false)}
                >
                    {({ processing, errors }) => (
                        <>
                            <TextareaField
                                label={t('portal.appointment.change_note')}
                                name="note"
                                required
                                rows={3}
                                maxLength={1000}
                                error={errors.note}
                            />
                            <TextField
                                label={t('portal.appointment.change_preferred')}
                                hint={t('portal.appointment.change_hint')}
                                name="preferred"
                                optional
                                maxLength={200}
                                error={errors.preferred}
                            />
                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    {t('portal.appointment.change_send')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setChanging(false)}
                                >
                                    {t('ui.cancel')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </Card>
    );
}

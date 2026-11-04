import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Phone } from 'lucide-react';
import AppointmentRequestController from '@/actions/App/Http/Controllers/AppointmentRequests/AppointmentRequestController';
import AppointmentController from '@/actions/App/Http/Controllers/Calendar/AppointmentController';
import CalendarController from '@/actions/App/Http/Controllers/Calendar/CalendarController';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import {
    RequestStatusBadge,
    usePreferredLabel,
} from '@/components/appointment-requests/parts';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { AppointmentRequestDetail } from '@/types/appointment-requests';

type Props = {
    request: AppointmentRequestDetail;
    assignable: { id: number; name: string }[];
    closeReasons: string[];
    can: { assign: boolean; close: boolean; schedule: boolean };
};

/** One appointment request (ADR-0039): call the customer, assign, book through ApptSchedule, or close with a reason. */
export default function AppointmentRequestShow({
    request: r,
    assignable,
    closeReasons,
    can,
}: Props) {
    const t = useT();
    const preferred = usePreferredLabel();

    const item = (label: TranslationKey, value: React.ReactNode) => (
        <div>
            <dt className="text-caption text-weak">{t(label)}</dt>
            <dd className="text-strong">{value}</dd>
        </div>
    );

    return (
        <>
            <Head title={r.reference ?? t('appointment_requests.title')} />
            <PageHeader
                title={`${r.reference ?? ''} · ${r.name}`}
                description={t(`appointments.kind.${r.kind}` as TranslationKey)}
                back={
                    <Link
                        href={AppointmentRequestController.index.url()}
                        className="inline-flex min-h-11 items-center gap-1 text-link hover:underline"
                    >
                        <Icon
                            icon={ArrowLeft}
                            size="sm"
                            className="rtl:rotate-180"
                        />
                        {t('appointment_requests.show.back')}
                    </Link>
                }
                actions={<RequestStatusBadge status={r.status} />}
            />

            <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <div className="flex flex-col gap-6">
                    <Card>
                        <h2 className="text-subtitle text-strong">
                            {t('appointment_requests.show.details')}
                        </h2>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            {item(
                                'appointment_requests.show.contact',
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    <bdi dir="ltr">{r.phone}</bdi>
                                    <a
                                        href={`tel:${r.phone}`}
                                        className={buttonVariants({
                                            variant: 'outline',
                                            size: 'sm',
                                        })}
                                    >
                                        <Icon icon={Phone} size="sm" />
                                        {t('appointment_requests.show.call')}
                                    </a>
                                </span>,
                            )}
                            {item(
                                'appointment_requests.show.preferred',
                                preferred(
                                    r.preferred_from,
                                    r.preferred_to,
                                    r.time_of_day,
                                ),
                            )}
                            {item(
                                'appointment_requests.field.city',
                                r.city ?? '—',
                            )}
                            {item(
                                'appointment_requests.field.address',
                                r.address ?? '—',
                            )}
                            {item(
                                'appointment_requests.show.source',
                                t(
                                    `appointment_requests.source.${r.source}` as TranslationKey,
                                ),
                            )}
                            {item(
                                'appointment_requests.filter.branch',
                                r.branch,
                            )}
                            {item(
                                'appointment_requests.col.created',
                                <bdi dir="ltr">{r.created_at}</bdi>,
                            )}
                            {r.due_at &&
                                item(
                                    'appointment_requests.col.due',
                                    <span className="inline-flex items-center gap-2">
                                        <bdi dir="ltr">{r.due_at}</bdi>
                                        {r.overdue && (
                                            <Badge variant="danger">
                                                {t(
                                                    'appointment_requests.overdue',
                                                )}
                                            </Badge>
                                        )}
                                    </span>,
                                )}
                        </dl>
                        {r.note && (
                            <p className="rounded-md bg-fill p-3 text-body whitespace-pre-line text-strong">
                                {r.note}
                            </p>
                        )}
                    </Card>

                    <Card>
                        <h2 className="text-subtitle text-strong">
                            {t('appointment_requests.show.linked')}
                        </h2>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            {r.customer &&
                                item(
                                    'appointment_requests.show.customer',
                                    <Link
                                        href={CustomerController.show.url(
                                            r.customer.id,
                                        )}
                                        className="text-link hover:underline"
                                    >
                                        {r.customer.name}
                                    </Link>,
                                )}
                            {r.interest &&
                                item(
                                    'appointment_requests.show.interest',
                                    <Link
                                        href={InterestController.show.url(
                                            r.interest.id,
                                        )}
                                        className="text-link hover:underline"
                                    >
                                        <bdi dir="ltr">
                                            {r.interest.reference}
                                        </bdi>
                                    </Link>,
                                )}
                            {r.order &&
                                item(
                                    'appointment_requests.show.order',
                                    <Link
                                        href={OrderController.show.url(
                                            r.order.id,
                                        )}
                                        className="text-link hover:underline"
                                    >
                                        <bdi dir="ltr">{r.order.number}</bdi>
                                    </Link>,
                                )}
                            {r.appointment_id &&
                                item(
                                    'appointment_requests.show.appointment',
                                    <Link
                                        href={AppointmentController.show.url(
                                            r.appointment_id,
                                        )}
                                        className="text-link hover:underline"
                                    >
                                        {t(
                                            'appointment_requests.show.open_appointment',
                                        )}
                                    </Link>,
                                )}
                        </dl>
                        {r.status === 'scheduled' && r.scheduled_at && (
                            <Alert variant="success">
                                <AlertBody>
                                    {t(
                                        'appointment_requests.show.scheduled_info',
                                        { at: r.scheduled_at },
                                    )}
                                </AlertBody>
                            </Alert>
                        )}
                        {r.status === 'closed' && r.closed_at && (
                            <Alert variant="info">
                                <AlertBody>
                                    {t(
                                        'appointment_requests.show.closed_info',
                                        {
                                            at: r.closed_at,
                                        },
                                    )}{' '}
                                    {r.close_reason &&
                                        t(
                                            `appointment_requests.close_reason.${r.close_reason}` as TranslationKey,
                                        )}
                                    {r.close_note ? ` — ${r.close_note}` : ''}
                                </AlertBody>
                            </Alert>
                        )}
                    </Card>
                </div>

                <div className="flex flex-col gap-6">
                    {can.schedule && (
                        <Card data-test="schedule-card">
                            <h2 className="text-subtitle text-strong">
                                {t('appointment_requests.show.schedule')}
                            </h2>
                            <p className="text-body text-weak">
                                {r.customer || r.order
                                    ? t(
                                          'appointment_requests.show.schedule_hint',
                                      )
                                    : t('appointment_requests.show.no_order')}
                            </p>
                            <Link
                                href={CalendarController.create.url({
                                    query: { request: r.id },
                                })}
                                className={buttonVariants({
                                    variant: 'default',
                                })}
                                data-test="schedule-request"
                            >
                                {t('appointment_requests.show.schedule')}
                            </Link>
                        </Card>
                    )}

                    {can.assign && (
                        <Card>
                            <h2 className="text-subtitle text-strong">
                                {t('appointment_requests.show.assign_title')}
                            </h2>
                            <Form
                                {...AppointmentRequestController.assign.form(
                                    r.id,
                                )}
                                className="flex flex-col gap-3"
                                data-test="assign-form"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="lock_version"
                                            value={r.lock_version}
                                        />
                                        <SelectField
                                            label={t(
                                                'appointment_requests.show.assignee',
                                            )}
                                            name="assignee_id"
                                            required
                                            defaultValue={String(
                                                r.assignee?.id ?? '',
                                            )}
                                            placeholder="—"
                                            options={assignable.map((u) => ({
                                                value: String(u.id),
                                                label: u.name,
                                            }))}
                                            error={errors.assignee_id}
                                        />
                                        <Button
                                            type="submit"
                                            variant="secondary"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'appointment_requests.show.assign_submit',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </Card>
                    )}

                    {can.close && (
                        <Card>
                            <h2 className="text-subtitle text-strong">
                                {t('appointment_requests.show.close_title')}
                            </h2>
                            <Form
                                {...AppointmentRequestController.close.form(
                                    r.id,
                                )}
                                className="flex flex-col gap-3"
                                data-test="close-form"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="lock_version"
                                            value={r.lock_version}
                                        />
                                        <SelectField
                                            label={t(
                                                'appointment_requests.show.close_reason',
                                            )}
                                            name="close_reason"
                                            required
                                            defaultValue="unreachable"
                                            options={closeReasons.map(
                                                (reason) => ({
                                                    value: reason,
                                                    label: t(
                                                        `appointment_requests.close_reason.${reason}` as TranslationKey,
                                                    ),
                                                }),
                                            )}
                                            error={errors.close_reason}
                                        />
                                        <TextareaField
                                            label={t(
                                                'appointment_requests.show.close_note',
                                            )}
                                            name="close_note"
                                            rows={2}
                                            maxLength={1000}
                                            optional
                                            error={errors.close_note}
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            {t(
                                                'appointment_requests.show.close_submit',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}

AppointmentRequestShow.layout = {
    breadcrumbs: [
        {
            title: 'appointment_requests.title',
            href: AppointmentRequestController.index(),
        },
    ],
};

import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { DateText } from '@/components/portal/parts';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { store } from '@/routes/customer/appointment-requests';
import { home } from '@/routes/customer';
import type { FieldMode } from '@/types/appointment-requests';

type Props = {
    enabled: boolean;
    orders: { id: number; number: string }[];
    order_id: number | null;
    kinds: string[];
    fields: Record<
        'preferred_date' | 'time_of_day' | 'city' | 'address' | 'note',
        FieldMode
    >;
    today: string;
    requests: {
        id: number;
        reference: string | null;
        kind: string;
        status: 'open' | 'scheduled' | 'closed';
        created_at: string;
    }[];
};

const TIMES = ['morning', 'afternoon', 'evening', 'any'];

/** The customer asks for an appointment (ADR-0039); staff call back to book it — no booking or approval here. */
export default function PortalAppointmentRequest({
    enabled,
    orders,
    order_id,
    kinds,
    fields,
    today,
    requests,
}: Props) {
    const t = useT();
    // One key per form instance: a double submit sends one request.
    const [clientKey] = useState(() => crypto.randomUUID());
    const shown = (f: keyof Props['fields']) => fields[f] !== 'off';
    const required = (f: keyof Props['fields']) => fields[f] === 'required';

    return (
        <>
            <Head title={t('appointment_requests.portal.title')} />
            <Link
                href={home().url}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                {t('appointment_requests.portal.back')}
            </Link>
            <h1 className="text-title text-strong">
                {t('appointment_requests.portal.title')}
            </h1>
            <p className="mt-1 mb-6 text-body text-weak">
                {t('appointment_requests.portal.description')}
            </p>

            {!enabled ? (
                <Alert variant="info">
                    <AlertBody>
                        {t('appointment_requests.portal.disabled')}
                    </AlertBody>
                </Alert>
            ) : (
                <Card>
                    <Form
                        {...store.form()}
                        className="flex flex-col gap-4"
                        data-test="portal-appointment-request"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="client_key"
                                    value={clientKey}
                                />
                                {errors.form && (
                                    <Alert variant="danger" role="alert">
                                        <AlertBody>{errors.form}</AlertBody>
                                    </Alert>
                                )}
                                <SelectField
                                    label={t('appointment_requests.field.kind')}
                                    name="kind"
                                    required
                                    defaultValue={kinds[0]}
                                    options={kinds.map((kind) => ({
                                        value: kind,
                                        label: t(
                                            `appointments.kind.${kind}` as TranslationKey,
                                        ),
                                    }))}
                                    error={errors.kind}
                                />
                                <SelectField
                                    label={t(
                                        'appointment_requests.field.order',
                                    )}
                                    name="order_id"
                                    defaultValue={
                                        order_id ? String(order_id) : ''
                                    }
                                    options={[
                                        {
                                            value: '',
                                            label: t(
                                                'appointment_requests.field.no_order',
                                            ),
                                        },
                                        ...orders.map((o) => ({
                                            value: String(o.id),
                                            label: o.number,
                                        })),
                                    ]}
                                    error={errors.order_id}
                                />
                                {shown('preferred_date') && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextField
                                            label={t(
                                                'appointment_requests.field.preferred_from',
                                            )}
                                            name="preferred_from"
                                            type="date"
                                            dir="ltr"
                                            min={today}
                                            required={required(
                                                'preferred_date',
                                            )}
                                            optional={
                                                !required('preferred_date')
                                            }
                                            error={errors.preferred_from}
                                        />
                                        <TextField
                                            label={t(
                                                'appointment_requests.field.preferred_to',
                                            )}
                                            name="preferred_to"
                                            type="date"
                                            dir="ltr"
                                            min={today}
                                            optional
                                            error={errors.preferred_to}
                                        />
                                    </div>
                                )}
                                {shown('time_of_day') && (
                                    <SelectField
                                        label={t(
                                            'appointment_requests.field.time_of_day',
                                        )}
                                        name="time_of_day"
                                        required={required('time_of_day')}
                                        defaultValue=""
                                        options={[
                                            ...(required('time_of_day')
                                                ? []
                                                : [{ value: '', label: '—' }]),
                                            ...TIMES.map((time) => ({
                                                value: time,
                                                label: t(
                                                    `appointment_requests.time.${time}` as TranslationKey,
                                                ),
                                            })),
                                        ]}
                                        error={errors.time_of_day}
                                    />
                                )}
                                {shown('city') && (
                                    <TextField
                                        label={t(
                                            'appointment_requests.field.city',
                                        )}
                                        name="city"
                                        maxLength={80}
                                        required={required('city')}
                                        optional={!required('city')}
                                        error={errors.city}
                                    />
                                )}
                                {shown('address') && (
                                    <TextareaField
                                        label={t(
                                            'appointment_requests.field.address',
                                        )}
                                        name="address"
                                        rows={2}
                                        maxLength={500}
                                        required={required('address')}
                                        optional={!required('address')}
                                        error={errors.address}
                                    />
                                )}
                                {shown('note') && (
                                    <TextareaField
                                        label={t(
                                            'appointment_requests.field.note',
                                        )}
                                        name="note"
                                        rows={3}
                                        maxLength={1000}
                                        required={required('note')}
                                        optional={!required('note')}
                                        error={errors.note}
                                    />
                                )}
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('appointment_requests.portal.request')}
                                </Button>
                            </>
                        )}
                    </Form>
                </Card>
            )}

            <h2 className="mt-8 mb-3 text-subtitle text-strong">
                {t('appointment_requests.portal.mine')}
            </h2>
            {requests.length === 0 ? (
                <p className="text-body text-weak">
                    {t('appointment_requests.portal.none')}
                </p>
            ) : (
                <ul className="flex flex-col gap-2" data-test="my-requests">
                    {requests.map((r) => (
                        <li
                            key={r.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-stroke-weak bg-card p-3"
                        >
                            <span className="min-w-0">
                                <span className="block text-label text-strong">
                                    {t(
                                        `appointments.kind.${r.kind}` as TranslationKey,
                                    )}
                                </span>
                                <span className="block text-caption text-weak">
                                    <bdi dir="ltr">{r.reference}</bdi>
                                    {t('portal.sep')}
                                    <DateText value={r.created_at} />
                                </span>
                            </span>
                            <Badge
                                variant={
                                    r.status === 'scheduled'
                                        ? 'success'
                                        : r.status === 'open'
                                          ? 'info'
                                          : 'neutral'
                                }
                            >
                                {t(
                                    `appointment_requests.portal.status.${r.status}` as TranslationKey,
                                )}
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

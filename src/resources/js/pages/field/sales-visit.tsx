import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Camera,
    CircleAlert,
    ExternalLink,
    FilePlus2,
    Flag,
    Link2,
    MapPinned,
    Ruler,
    Send,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { useRef, useState } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import FieldLeadController from '@/actions/App/Http/Controllers/FieldSales/FieldLeadController';
import SalesVisitController from '@/actions/App/Http/Controllers/FieldSales/SalesVisitController';
import AgreementController from '@/actions/App/Http/Controllers/Agreements/AgreementController';
import { ReachButtons, StatusBadge } from '@/components/field/field-bits';
import { Step, extensionOf, useQueued } from '@/components/field/field-steps';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { compressImage, discard, enqueue, flush } from '@/lib/field-queue';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SalesVisitDetail } from '@/types/field';

type Props = { visit: SalesVisitDetail; today: string };

/**
 * A field sales visit or measurement, top to bottom (ADR-0041): who and where → «وصلت» → notes → site photos →
 * measurements → quote and the customer's links → «انتهت الزيارة». Each step posts to the server, which checks it
 * again through the rep's own grants on the order.
 */
export default function SalesVisit({ visit, today }: Props) {
    const t = useT();
    const { errors } = usePage().props;
    const site = visit.visit;
    const running = visit.can.work;
    const { items: pending, refresh } = useQueued(visit.id);
    const [taking, setTaking] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const camera = useRef<HTMLInputElement>(null);
    const url = { appointment: visit.id };

    const note = useForm({
        note: site?.note ?? '',
        lock_version: site?.lock_version ?? null,
    });
    const measure = useForm({
        location: '',
        name: '',
        value: '',
        unit: visit.units.includes('m') ? 'm' : (visit.units[0] ?? 'cm'),
        kind: visit.kind === 'measurement' ? 'final' : 'estimated',
        measured_on: today,
        note: '',
    });
    const finish = useForm({ outcome: '' });

    const post = (key: string, target: string, data = {}) => {
        setBusy(key);
        router.post(target, data, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const take = async (file: File) => {
        setTaking(true);
        try {
            const blob = await compressImage(file);
            await enqueue({
                id: crypto.randomUUID(),
                url: SalesVisitController.photo.url(url),
                appointmentId: visit.id,
                phase: 'site',
                blob,
                name: `site.${extensionOf(blob, file)}`,
                xsrf: '',
                created: Date.now(),
            });
            await refresh();
            await flush();
            await refresh();
            router.reload({ only: ['visit'] });
        } finally {
            setTaking(false);
        }
    };

    const waiting = pending.filter(
        (p) => !visit.photos.some((s) => s.client_uuid === p.id),
    );

    return (
        <>
            <Head title={visit.customer.name} />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Link
                    href={FieldController.index.url()}
                    className="text-label text-link"
                >
                    {t('field.back')}
                </Link>
                <span className="ms-auto flex gap-2">
                    <Badge variant="neutral">
                        {t(`appointments.kind.${visit.kind}` as TranslationKey)}
                    </Badge>
                    <StatusBadge status={visit.status} />
                </span>
            </div>

            {errors.field && (
                <Alert variant="danger" className="mb-4" role="alert">
                    <Icon icon={CircleAlert} />
                    <AlertBody>{errors.field}</AlertBody>
                </Alert>
            )}

            <div className="flex flex-col gap-4">
                <section className="rounded-lg border border-stroke-weak bg-surface p-4">
                    <h1 className="text-heading m-0 text-strong">
                        {visit.customer.name}
                    </h1>
                    {visit.customer.contact && (
                        <p className="m-0 text-caption text-weak">
                            {t('field.contact', {
                                name: visit.customer.contact,
                            })}
                        </p>
                    )}
                    <p className="mt-1 mb-0 text-caption text-weak">
                        <span dir="ltr">
                            {visit.date} · {visit.start}–{visit.end}
                        </span>{' '}
                        · {visit.order.number} ·{' '}
                        {t(
                            `field_sales.channel.${visit.order.channel}` as TranslationKey,
                        )}
                    </p>
                    <p className="mt-2 mb-3 text-body">{visit.address}</p>
                    <ReachButtons
                        visit={{
                            ...visit,
                            order: {
                                id: visit.order.id,
                                number: visit.order.number,
                            },
                        }}
                    />
                    {visit.notes && (
                        <>
                            <h2 className="mt-4 mb-1 text-label text-strong">
                                {t('field.notes')}
                            </h2>
                            <p className="m-0 text-body whitespace-pre-line">
                                {visit.notes}
                            </p>
                        </>
                    )}
                    {visit.lead && (
                        <p className="mt-3 mb-0 text-caption">
                            <Link
                                href={FieldLeadController.show.url({
                                    interest: visit.lead.id,
                                })}
                                className="text-link"
                            >
                                {t('field_sales.visit.lead', {
                                    reference: visit.lead.reference ?? '',
                                })}
                            </Link>
                        </p>
                    )}
                </section>

                <Step n={1} title={t('field.step.arrive')} done={site !== null}>
                    {site === null ? (
                        <div className="flex flex-col gap-3">
                            <Button
                                type="button"
                                block
                                loading={busy === 'start'}
                                disabled={!visit.can.start}
                                onClick={() =>
                                    post(
                                        'start',
                                        SalesVisitController.start.url(url),
                                    )
                                }
                                data-test="sales-start"
                            >
                                <Icon icon={MapPinned} />
                                {t('field.arrive.button')}
                            </Button>
                            {visit.appointment_status !== 'confirmed' && (
                                <p className="m-0 text-caption text-weak">
                                    {t('field.arrive.not_confirmed')}
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="m-0 text-body">
                            {t('field.arrive.done', { at: site.arrived_at })}
                        </p>
                    )}
                </Step>

                <Step
                    n={2}
                    title={t('field_sales.visit.notes')}
                    done={!!site?.note}
                    disabled={!running}
                >
                    <form
                        className="flex flex-col gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            note.post(SalesVisitController.note.url(url), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <TextareaField
                            label={t('field_sales.visit.notes_label')}
                            hint={t('field_sales.visit.notes_hint')}
                            value={note.data.note}
                            error={note.errors.note}
                            disabled={!running}
                            rows={4}
                            onChange={(e) =>
                                note.setData('note', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            variant="secondary"
                            block
                            loading={note.processing}
                            disabled={!running}
                        >
                            {t('field_sales.visit.save_notes')}
                        </Button>
                    </form>
                </Step>

                <Step
                    n={3}
                    title={t('field_sales.visit.photos')}
                    done={visit.photos.length > 0}
                    disabled={!running}
                >
                    <div
                        className="flex flex-col gap-2"
                        data-test="sales-photos"
                    >
                        <span className="text-caption text-weak">
                            {t('field.photos.count', {
                                count: visit.photos.length,
                            })}
                        </span>
                        <div className="grid grid-cols-3 gap-2">
                            {visit.photos.map((photo) => (
                                <img
                                    key={photo.id}
                                    src={photo.url}
                                    alt={t('field_sales.visit.photo_alt')}
                                    loading="lazy"
                                    className="aspect-square w-full rounded-md border border-stroke-weak bg-fill object-cover"
                                />
                            ))}
                            {waiting.map((item) => (
                                <div key={item.id} className="relative">
                                    <img
                                        src={item.preview}
                                        alt=""
                                        className="aspect-square w-full rounded-md border border-dashed border-stroke-control object-cover opacity-70"
                                    />
                                    <span
                                        className={cn(
                                            'absolute inset-x-1 bottom-1 rounded-sm px-1 text-center text-caption',
                                            item.failed
                                                ? 'bg-danger-bg text-danger-text'
                                                : 'bg-surface/90 text-weak',
                                        )}
                                    >
                                        {item.failed
                                            ? t('field.photos.refused')
                                            : t('field.photos.waiting')}
                                    </span>
                                    {item.failed && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void discard(item.id).then(
                                                    refresh,
                                                )
                                            }
                                            className="absolute end-1 top-1 rounded-full bg-surface p-1"
                                            aria-label={t(
                                                'field.photos.discard',
                                            )}
                                        >
                                            <Icon icon={Trash2} size="sm" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <input
                            ref={camera}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            className="sr-only"
                            tabIndex={-1}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (file) {
                                    void take(file);
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            block
                            loading={taking}
                            disabled={
                                !running ||
                                visit.photos.length >= visit.max_photos
                            }
                            onClick={() => camera.current?.click()}
                        >
                            <Icon icon={Camera} />
                            {t('field_sales.visit.take_photo')}
                        </Button>
                    </div>
                </Step>

                <Step
                    n={4}
                    title={t('field_sales.visit.measurements')}
                    done={visit.measurements.length > 0}
                    disabled={!visit.can.measure}
                >
                    {visit.measurements.length > 0 && (
                        <ul
                            className="mb-3 flex list-none flex-col gap-1 p-0"
                            data-test="sales-measurements"
                        >
                            {visit.measurements.map((m) => (
                                <li
                                    key={m.id}
                                    className="flex justify-between gap-2 border-b border-stroke-weak py-1 text-body last:border-0"
                                >
                                    <span>
                                        {m.location} — {m.name}
                                    </span>
                                    <bdi dir="ltr" className="text-strong">
                                        {m.value} {m.unit}
                                    </bdi>
                                </li>
                            ))}
                        </ul>
                    )}
                    {visit.can.measure ? (
                        <form
                            className="grid grid-cols-2 gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                measure.post(
                                    SalesVisitController.measure.url(url),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            measure.setData({
                                                ...measure.data,
                                                name: '',
                                                value: '',
                                                note: '',
                                            }),
                                    },
                                );
                            }}
                        >
                            <TextField
                                className="col-span-2"
                                label={t('field_sales.visit.m_location')}
                                value={measure.data.location}
                                error={measure.errors.location}
                                onChange={(e) =>
                                    measure.setData('location', e.target.value)
                                }
                            />
                            <TextField
                                className="col-span-2"
                                label={t('field_sales.visit.m_name')}
                                value={measure.data.name}
                                error={measure.errors.name}
                                onChange={(e) =>
                                    measure.setData('name', e.target.value)
                                }
                            />
                            <TextField
                                label={t('field_sales.visit.m_value')}
                                inputMode="decimal"
                                dir="ltr"
                                value={measure.data.value}
                                error={measure.errors.value}
                                onChange={(e) =>
                                    measure.setData('value', e.target.value)
                                }
                            />
                            <SelectField
                                label={t('field_sales.visit.m_unit')}
                                value={measure.data.unit}
                                onChange={(e) =>
                                    measure.setData('unit', e.target.value)
                                }
                                options={visit.units.map((u) => ({
                                    value: u,
                                    label: u,
                                }))}
                            />
                            <SelectField
                                className="col-span-2"
                                label={t('field_sales.visit.m_kind')}
                                value={measure.data.kind}
                                onChange={(e) =>
                                    measure.setData('kind', e.target.value)
                                }
                                options={['estimated', 'final'].map((k) => ({
                                    value: k,
                                    label: t(
                                        `field_sales.visit.m_kind_${k}` as TranslationKey,
                                    ),
                                }))}
                            />
                            <Button
                                type="submit"
                                variant="secondary"
                                className="col-span-2"
                                loading={measure.processing}
                            >
                                <Icon icon={Ruler} />
                                {t('field_sales.visit.add_measurement')}
                            </Button>
                        </form>
                    ) : (
                        <p className="m-0 text-caption text-weak">
                            {running
                                ? t('field_sales.visit.no_measure_grant')
                                : t('field_sales.visit.start_first')}
                        </p>
                    )}
                </Step>

                <Step
                    n={5}
                    title={t('field_sales.visit.quote')}
                    done={visit.documents.some((d) =>
                        ['awaiting_customer', 'approved'].includes(d.status),
                    )}
                >
                    <div className="flex flex-col gap-3">
                        {visit.documents.length === 0 && (
                            <p className="m-0 text-caption text-weak">
                                {t('field_sales.visit.no_quote')}
                            </p>
                        )}
                        {visit.documents.map((doc) => (
                            <div
                                key={doc.id}
                                className="flex flex-col gap-2 rounded-md border border-stroke-weak p-3"
                                data-test="sales-document"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-label text-strong">
                                        {t(
                                            `agreements.kind.${doc.kind}` as TranslationKey,
                                        )}{' '}
                                        {doc.number}
                                    </span>
                                    <Badge variant="info">
                                        {t(
                                            `agreements.document.status.${doc.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </div>
                                {doc.can_send && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        block
                                        loading={busy === `send-${doc.id}`}
                                        onClick={() =>
                                            post(
                                                `send-${doc.id}`,
                                                SalesVisitController.sendDocument.url(
                                                    {
                                                        appointment: visit.id,
                                                        document: doc.id,
                                                    },
                                                ),
                                                {
                                                    lock_version:
                                                        doc.lock_version,
                                                },
                                            )
                                        }
                                    >
                                        <Icon icon={Send} />
                                        {t('field_sales.visit.send_document')}
                                    </Button>
                                )}
                                {doc.can_sign_link && (
                                    <Button
                                        type="button"
                                        block
                                        loading={busy === `link-${doc.id}`}
                                        onClick={() =>
                                            post(
                                                `link-${doc.id}`,
                                                SalesVisitController.signLink.url(
                                                    {
                                                        appointment: visit.id,
                                                        document: doc.id,
                                                    },
                                                ),
                                            )
                                        }
                                        data-test="sales-sign-link"
                                    >
                                        <Icon icon={Link2} />
                                        {t('field_sales.visit.sign_link')}
                                    </Button>
                                )}
                            </div>
                        ))}
                        {visit.can.create_quote && (
                            <Button
                                type="button"
                                variant="secondary"
                                block
                                loading={busy === 'quote'}
                                onClick={() =>
                                    post(
                                        'quote',
                                        SalesVisitController.createQuote.url(
                                            url,
                                        ),
                                    )
                                }
                                data-test="sales-create-quote"
                            >
                                <Icon icon={FilePlus2} />
                                {t('field_sales.visit.create_quote')}
                            </Button>
                        )}
                        {visit.can.edit_quote && visit.documents.length > 0 && (
                            <Button asChild variant="ghost" block>
                                <a
                                    href={AgreementController.show.url({
                                        order: visit.order.id,
                                    })}
                                >
                                    <Icon icon={ExternalLink} />
                                    {t('field_sales.visit.edit_quote')}
                                </a>
                            </Button>
                        )}
                        {visit.can.portal_invite && (
                            <Button
                                type="button"
                                variant="ghost"
                                block
                                loading={busy === 'invite'}
                                onClick={() =>
                                    post(
                                        'invite',
                                        SalesVisitController.portalInvite.url(
                                            url,
                                        ),
                                    )
                                }
                            >
                                <Icon icon={UserPlus} />
                                {t('field_sales.visit.portal_invite')}
                            </Button>
                        )}
                        {!visit.customer_portal && (
                            <p className="m-0 text-caption text-weak">
                                {t('field_sales.visit.portal_disabled')}
                            </p>
                        )}
                    </div>
                </Step>

                <Step
                    n={6}
                    title={t('field_sales.visit.finish')}
                    done={site?.finished_at != null}
                    disabled={!running}
                >
                    {site?.finished_at ? (
                        <p className="m-0 text-body">
                            {t('field_sales.visit.finished_at', {
                                at: site.finished_at,
                            })}
                            {site.outcome && ` — ${site.outcome}`}
                        </p>
                    ) : (
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                finish.post(
                                    SalesVisitController.finish.url(url),
                                );
                            }}
                        >
                            <TextareaField
                                label={t('field_sales.visit.outcome')}
                                hint={t('field_sales.visit.outcome_hint')}
                                value={finish.data.outcome}
                                error={finish.errors.outcome}
                                disabled={!running}
                                rows={3}
                                onChange={(e) =>
                                    finish.setData('outcome', e.target.value)
                                }
                            />
                            <Button
                                type="submit"
                                block
                                loading={finish.processing}
                                disabled={!running}
                                data-test="sales-finish"
                            >
                                <Icon icon={Flag} />
                                {t('field_sales.visit.finish_button')}
                            </Button>
                        </form>
                    )}
                </Step>
            </div>
        </>
    );
}

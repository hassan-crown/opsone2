import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Camera,
    Check,
    CircleAlert,
    Flag,
    LoaderCircle,
    MapPinned,
    PenLine,
    Send,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import FieldVisitController from '@/actions/App/Http/Controllers/Field/FieldVisitController';
import {
    ReachButtons,
    StatusBadge,
    useDuration,
} from '@/components/field/field-bits';
import {
    CheckboxField,
    FieldError,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { SignaturePad } from '@/components/portal/sign-panel';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    compressImage,
    discard,
    enqueue,
    flush,
    queued,
} from '@/lib/field-queue';
import type { QueuedUpload } from '@/lib/field-queue';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { FieldVisitDetail, RemarkKind } from '@/types/field';

type Props = { visit: FieldVisitDetail; today: string };
type Phase = 'before' | 'after';

/** The real type decides the name: the server checks extension, MIME type and content together. */
function extensionOf(blob: Blob, file: File): string {
    const byType: Record<string, string> = {
        'image/jpeg': 'jpg',
        'image/png': 'png',
        'image/webp': 'webp',
    };

    return byType[blob.type] ?? file.name.split('.').pop() ?? 'jpg';
}

const KINDS: RemarkKind[] = ['missing_part', 'damage', 'extra_work', 'other'];

function Step({
    n,
    title,
    done,
    children,
    disabled,
}: {
    n: number;
    title: string;
    done: boolean;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'rounded-lg border border-stroke-weak bg-surface p-4',
                disabled && 'opacity-60',
            )}
            aria-labelledby={`step-${n}`}
        >
            <h2
                id={`step-${n}`}
                className="mb-3 flex items-center gap-2 text-title text-strong"
            >
                <span
                    className={cn(
                        'inline-flex size-7 shrink-0 items-center justify-center rounded-full text-label',
                        done
                            ? 'bg-success-bg text-success-text'
                            : 'bg-fill text-weak',
                    )}
                    aria-hidden="true"
                >
                    {done ? <Icon icon={Check} size="sm" /> : n}
                </span>
                {title}
            </h2>
            {children}
        </section>
    );
}

/** Pending local photos of this visit from the device queue, with object URLs for previews. */
function useQueued(appointmentId: number) {
    const [items, setItems] = useState<(QueuedUpload & { preview: string })[]>(
        [],
    );
    const refresh = useCallback(async () => {
        const all = await queued().catch(() => []);
        setItems((previous) => {
            previous.forEach((p) => URL.revokeObjectURL(p.preview));

            return all
                .filter((item) => item.appointmentId === appointmentId)
                .map((item) => ({
                    ...item,
                    preview: URL.createObjectURL(item.blob),
                }));
        });
    }, [appointmentId]);

    useEffect(() => {
        void refresh();
        const onMessage = (event: MessageEvent) => {
            if (event.data?.type === 'uploads-flushed') {
                void refresh();
                router.reload({ only: ['visit'] });
            }
        };
        navigator.serviceWorker?.addEventListener('message', onMessage);

        return () =>
            navigator.serviceWorker?.removeEventListener('message', onMessage);
    }, [refresh]);

    return { items, refresh };
}

function PhotoStrip({
    visit,
    phase,
    pending,
    onTake,
    onDiscard,
    busy,
    disabled,
}: {
    visit: FieldVisitDetail;
    phase: Phase;
    pending: (QueuedUpload & { preview: string })[];
    onTake: (phase: Phase, file: File) => void;
    onDiscard: (id: string) => void;
    busy: boolean;
    disabled: boolean;
}) {
    const t = useT();
    const input = useRef<HTMLInputElement>(null);
    const stored = visit.photos.filter((p) => p.phase === phase);
    const waiting = pending.filter(
        (p) => p.phase === phase && !stored.some((s) => s.client_uuid === p.id),
    );

    return (
        <div className="flex flex-col gap-2" data-test={`photos-${phase}`}>
            <div className="flex items-center justify-between gap-2">
                <span className="text-label text-strong">
                    {t(`field.photos.${phase}` as TranslationKey)}
                </span>
                <span className="text-caption text-weak">
                    {t('field.photos.count', { count: stored.length })}
                </span>
            </div>
            <div className="grid grid-cols-3 gap-2">
                {stored.map((photo) => (
                    <img
                        key={photo.id}
                        src={photo.url}
                        alt={t(`field.photos.${phase}` as TranslationKey)}
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
                                onClick={() => onDiscard(item.id)}
                                className="absolute end-1 top-1 rounded-full bg-surface p-1"
                                aria-label={t('field.photos.discard')}
                            >
                                <Icon icon={Trash2} size="sm" />
                            </button>
                        )}
                    </div>
                ))}
            </div>
            <input
                ref={input}
                type="file"
                accept="image/*"
                capture="environment"
                className="sr-only"
                tabIndex={-1}
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    e.target.value = '';
                    if (file) {
                        onTake(phase, file);
                    }
                }}
            />
            <Button
                type="button"
                variant="secondary"
                block
                loading={busy}
                disabled={disabled}
                onClick={() => input.current?.click()}
            >
                <Icon icon={Camera} />
                {t(`field.photos.take_${phase}` as TranslationKey)}
            </Button>
        </div>
    );
}

/**
 * One installation visit, top to bottom (ADR-0036): who and where → «وصلت» → photos before/after → remarks →
 * the customer's acceptance → «انتهى». Each step posts to the server, which checks it again.
 */
export default function FieldVisit({ visit }: Props) {
    const t = useT();
    const duration = useDuration();
    const { errors } = usePage().props;
    const site = visit.visit;
    const finished = site?.finished_at != null;
    const open = site !== null && !finished;
    const { items: pending, refresh } = useQueued(visit.id);
    const [busyPhase, setBusyPhase] = useState<Phase | null>(null);
    const [locate, setLocate] = useState(true);
    const [arriving, setArriving] = useState(false);
    const [signMode, setSignMode] = useState<'here' | null>(null);

    const remark = useForm({
        kind: 'missing_part' as RemarkKind,
        description: '',
    });
    const sign = useForm({
        signer_name: '',
        signature: '',
        consent: false,
    });
    const finish = useForm({
        note: '',
        lock_version: site?.lock_version ?? null,
    });

    useEffect(() => {
        finish.setData('lock_version', site?.lock_version ?? null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [site?.lock_version]);

    const arrive = () => {
        const post = (coords?: GeolocationCoordinates) =>
            router.post(
                FieldVisitController.arrive.url({ appointment: visit.id }),
                coords
                    ? {
                          latitude: coords.latitude,
                          longitude: coords.longitude,
                          accuracy: Math.round(coords.accuracy),
                      }
                    : {},
                {
                    preserveScroll: true,
                    onFinish: () => setArriving(false),
                },
            );
        setArriving(true);
        if (locate && 'geolocation' in navigator) {
            // One reading, only now, only with the browser's permission; no tracking afterwards.
            navigator.geolocation.getCurrentPosition(
                (position) => post(position.coords),
                () => post(),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
            );
        } else {
            post();
        }
    };

    const take = async (phase: Phase, file: File) => {
        setBusyPhase(phase);
        try {
            const blob = await compressImage(file);
            await enqueue({
                id: crypto.randomUUID(),
                url: FieldVisitController.photo.url({ appointment: visit.id }),
                appointmentId: visit.id,
                phase,
                blob,
                name: `${phase}.${extensionOf(blob, file)}`,
                xsrf: '',
                created: Date.now(),
            });
            await refresh();
            await flush();
            await refresh();
            router.reload({ only: ['visit'] });
        } finally {
            setBusyPhase(null);
        }
    };

    const count = (phase: Phase) =>
        visit.photos.filter((p) => p.phase === phase).length;
    const photosReady =
        count('before') >= visit.min_photos &&
        count('after') >= visit.min_photos;
    const signed = site?.signed ?? false;
    const ready = open && photosReady && signed;

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
                <span className="ms-auto">
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
                        · {visit.order.number}
                    </p>
                    <p className="mt-2 mb-3 text-body">{visit.address}</p>
                    <ReachButtons visit={visit} />
                    {visit.items.length > 0 && (
                        <>
                            <h2 className="mt-4 mb-1 text-label text-strong">
                                {t('field.items')}
                            </h2>
                            <ul className="m-0 ps-5 text-body">
                                {visit.items.map((item, i) => (
                                    <li key={i}>
                                        {item.name} ×{' '}
                                        <span dir="ltr">{item.quantity}</span>
                                        {item.unit ? ` ${item.unit}` : ''}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
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
                    {visit.team.length > 1 && (
                        <p className="mt-3 mb-0 text-caption text-weak">
                            {t('field.team', {
                                names: visit.team.join('، '),
                            })}
                        </p>
                    )}
                </section>

                <Step n={1} title={t('field.step.arrive')} done={site !== null}>
                    {site === null ? (
                        <div className="flex flex-col gap-3">
                            <CheckboxField
                                label={t('field.arrive.locate')}
                                hint={t('field.arrive.locate_hint')}
                                checked={locate}
                                onChange={(e) => setLocate(e.target.checked)}
                            />
                            <Button
                                type="button"
                                block
                                loading={arriving}
                                disabled={
                                    visit.appointment_status !== 'confirmed'
                                }
                                onClick={arrive}
                                data-test="arrive"
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
                            {site.located && ` · ${t('field.arrive.located')}`}
                        </p>
                    )}
                </Step>

                <Step
                    n={2}
                    title={t('field.step.photos')}
                    done={photosReady}
                    disabled={site === null}
                >
                    <p className="mt-0 mb-3 text-caption text-weak">
                        {t('field.photos.rule', { min: visit.min_photos })}
                    </p>
                    <div className="flex flex-col gap-4">
                        {(['before', 'after'] as Phase[]).map((phase) => (
                            <PhotoStrip
                                key={phase}
                                visit={visit}
                                phase={phase}
                                pending={pending}
                                busy={busyPhase === phase}
                                disabled={!open}
                                onTake={(p, f) => void take(p, f)}
                                onDiscard={(id) =>
                                    void discard(id).then(refresh)
                                }
                            />
                        ))}
                    </div>
                </Step>

                <Step
                    n={3}
                    title={t('field.step.remarks')}
                    done={visit.remarks.length > 0}
                    disabled={site === null}
                >
                    {visit.remarks.length > 0 && (
                        <ul className="mt-0 mb-3 flex list-none flex-col gap-2 p-0">
                            {visit.remarks.map((r) => (
                                <li
                                    key={r.id}
                                    className="rounded-md border border-stroke-weak p-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2 text-label">
                                        {t(
                                            `installation.remark.kind.${r.kind}` as TranslationKey,
                                        )}
                                        <span
                                            className={cn(
                                                'rounded-sm px-2 text-caption',
                                                r.status === 'open'
                                                    ? 'bg-warning-bg text-warning-text'
                                                    : 'bg-neutral-bg text-neutral-text',
                                            )}
                                        >
                                            {t(
                                                `installation.remark.status.${r.status}` as TranslationKey,
                                            )}
                                        </span>
                                    </div>
                                    <p className="mt-1 mb-0 text-body">
                                        {r.description}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    {open && (
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                remark.post(
                                    FieldVisitController.remark.url({
                                        appointment: visit.id,
                                    }),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            remark.reset('description'),
                                    },
                                );
                            }}
                        >
                            <SelectField
                                label={t('field.remark.kind')}
                                value={remark.data.kind}
                                onChange={(e) =>
                                    remark.setData(
                                        'kind',
                                        e.target.value as RemarkKind,
                                    )
                                }
                                options={KINDS.map((k) => ({
                                    value: k,
                                    label: t(
                                        `installation.remark.kind.${k}` as TranslationKey,
                                    ),
                                }))}
                            />
                            <TextareaField
                                label={t('field.remark.description')}
                                value={remark.data.description}
                                onChange={(e) =>
                                    remark.setData(
                                        'description',
                                        e.target.value,
                                    )
                                }
                                error={remark.errors.description}
                                required
                            />
                            <p className="m-0 text-caption text-weak">
                                {t('field.remark.blocking_hint')}
                            </p>
                            <Button
                                type="submit"
                                variant="secondary"
                                loading={remark.processing}
                            >
                                <Icon icon={Flag} />
                                {t('field.remark.add')}
                            </Button>
                        </form>
                    )}
                </Step>

                <Step
                    n={4}
                    title={t('field.step.signature')}
                    done={signed}
                    disabled={site === null}
                >
                    {signed && site ? (
                        <div className="flex flex-col gap-2">
                            <p className="m-0 text-body">
                                {t('field.signature.done', {
                                    name: site.signer_name ?? '',
                                    at: site.signed_at ?? '',
                                })}
                            </p>
                            <p className="m-0 text-caption text-weak">
                                {t(
                                    `field.signature.method.${site.signature_method ?? 'device'}` as TranslationKey,
                                )}
                            </p>
                            {site.signature_url && (
                                <img
                                    src={site.signature_url}
                                    alt={t('field.signature.image')}
                                    className="max-h-32 w-full rounded-md border border-stroke-weak bg-white object-contain"
                                />
                            )}
                        </div>
                    ) : open ? (
                        <div className="flex flex-col gap-3">
                            {signMode !== 'here' && (
                                <div className="grid gap-2">
                                    <Button
                                        type="button"
                                        onClick={() => setSignMode('here')}
                                    >
                                        <Icon icon={PenLine} />
                                        {t('field.signature.here')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() =>
                                            router.post(
                                                FieldVisitController.signatureLink.url(
                                                    { appointment: visit.id },
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Icon icon={Send} />
                                        {site?.signature_requested
                                            ? t('field.signature.resend')
                                            : t('field.signature.link')}
                                    </Button>
                                    {site?.signature_requested && (
                                        <p className="m-0 text-caption text-weak">
                                            {t('field.signature.link_sent')}
                                        </p>
                                    )}
                                </div>
                            )}
                            {signMode === 'here' && (
                                <form
                                    className="flex flex-col gap-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        sign.post(
                                            FieldVisitController.sign.url({
                                                appointment: visit.id,
                                            }),
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <p className="m-0 text-caption text-weak">
                                        {t('field.signature.hand_over')}
                                    </p>
                                    <TextField
                                        label={t('field.signature.name')}
                                        value={sign.data.signer_name}
                                        onChange={(e) =>
                                            sign.setData(
                                                'signer_name',
                                                e.target.value,
                                            )
                                        }
                                        error={sign.errors.signer_name}
                                        autoComplete="off"
                                        required
                                    />
                                    <SignaturePad
                                        label={t('field.signature.pad')}
                                        clearLabel={t('field.signature.clear')}
                                        invalid={Boolean(sign.errors.signature)}
                                        onChange={(data) =>
                                            sign.setData(
                                                'signature',
                                                data ?? '',
                                            )
                                        }
                                    />
                                    {sign.errors.signature && (
                                        <FieldError>
                                            {sign.errors.signature}
                                        </FieldError>
                                    )}
                                    <CheckboxField
                                        label={t('field.signature.consent')}
                                        checked={sign.data.consent}
                                        onChange={(e) =>
                                            sign.setData(
                                                'consent',
                                                e.target.checked,
                                            )
                                        }
                                        error={sign.errors.consent}
                                    />
                                    <div className="grid grid-cols-2 gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => setSignMode(null)}
                                        >
                                            {t('field.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            loading={sign.processing}
                                            disabled={
                                                !sign.data.signature ||
                                                !sign.data.consent
                                            }
                                        >
                                            {t('field.signature.save')}
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>
                    ) : null}
                </Step>

                <Step
                    n={5}
                    title={t('field.step.finish')}
                    done={finished}
                    disabled={!open && !finished}
                >
                    {finished && site ? (
                        <p className="m-0 text-body">
                            {t('field.finish.done', {
                                at: site.finished_at ?? '',
                                duration: duration(site.duration_minutes),
                            })}
                        </p>
                    ) : (
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                finish.post(
                                    FieldVisitController.finish.url({
                                        appointment: visit.id,
                                    }),
                                );
                            }}
                        >
                            <ul className="m-0 flex list-none flex-col gap-1 p-0 text-caption">
                                {[
                                    [
                                        site !== null,
                                        'field.finish.need_arrival',
                                    ],
                                    [
                                        count('before') >= visit.min_photos,
                                        'field.finish.need_before',
                                    ],
                                    [
                                        count('after') >= visit.min_photos,
                                        'field.finish.need_after',
                                    ],
                                    [signed, 'field.finish.need_signature'],
                                ].map(([ok, key]) => (
                                    <li
                                        key={key as string}
                                        className={cn(
                                            'flex items-center gap-1',
                                            ok
                                                ? 'text-success-text'
                                                : 'text-weak',
                                        )}
                                    >
                                        <Icon
                                            icon={ok ? Check : LoaderCircle}
                                            size="sm"
                                        />
                                        {t(key as TranslationKey)}
                                    </li>
                                ))}
                            </ul>
                            {pending.some((p) => !p.failed) && (
                                <p className="m-0 text-caption text-warning-text">
                                    {t('field.finish.uploads_waiting')}
                                </p>
                            )}
                            <TextareaField
                                label={t('field.finish.note')}
                                optional
                                value={finish.data.note}
                                onChange={(e) =>
                                    finish.setData('note', e.target.value)
                                }
                                error={finish.errors.note}
                                disabled={!open}
                            />
                            <Button
                                type="submit"
                                block
                                loading={finish.processing}
                                disabled={!ready}
                                data-test="finish"
                            >
                                <Icon icon={Check} />
                                {t('field.finish.button')}
                            </Button>
                        </form>
                    )}
                </Step>
            </div>
        </>
    );
}

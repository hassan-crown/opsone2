import { router } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import { useState } from 'react';
import InstallationRemarkController from '@/actions/App/Http/Controllers/Field/InstallationRemarkController';
import { StatusBadge, useDuration } from '@/components/field/field-bits';
import { TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { InstallationRemark, OrderInstallation } from '@/types/field';

function ResolveRemark({ remark }: { remark: InstallationRemark }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    if (!open) {
        return (
            <Button
                type="button"
                size="sm"
                variant="secondary"
                onClick={() => setOpen(true)}
            >
                {t('installation.remark.resolve')}
            </Button>
        );
    }

    return (
        <div className="mt-2 flex flex-col gap-2">
            <TextareaField
                label={t('installation.remark.resolution')}
                value={text}
                onChange={(e) => setText(e.target.value)}
                error={error}
            />
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    loading={busy}
                    onClick={() =>
                        router.post(
                            InstallationRemarkController.resolve.url({
                                remark: remark.id,
                            }),
                            {
                                resolution: text,
                                lock_version: remark.lock_version,
                            },
                            {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onError: (e) =>
                                    setError(
                                        e.resolution ?? e.transition ?? e.field,
                                    ),
                            },
                        )
                    }
                >
                    {t('installation.remark.resolve_save')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setOpen(false)}
                >
                    {t('field.cancel')}
                </Button>
            </div>
        </div>
    );
}

/**
 * The installation card of the order page (ADR-0036): each visit's status, actual arrival and duration, the
 * before/after photos, the customer's acceptance signature and the remarks (each blocks the closure until it is resolved on its own).
 */
export function InstallationCard({
    installation,
}: {
    installation: OrderInstallation;
}) {
    const t = useT();
    const duration = useDuration();

    return (
        <Card data-test="order-installation">
            <h2 className="text-heading mb-2 flex items-center gap-2 text-strong">
                <Icon icon={Wrench} />
                {t('installation.card.title')}
            </h2>
            <div className="flex flex-col gap-4">
                {installation.visits.map((v) => (
                    <section
                        key={v.appointment_id}
                        className="flex flex-col gap-2 border-t border-stroke-weak pt-3 first:border-t-0 first:pt-0"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-label text-strong" dir="ltr">
                                {v.date} {v.start}
                            </span>
                            <StatusBadge status={v.status} />
                            {v.team.length > 0 && (
                                <span className="text-caption text-weak">
                                    {v.team.join('، ')}
                                </span>
                            )}
                        </div>
                        {v.arrived_at && (
                            <dl className="m-0 grid grid-cols-2 gap-x-4 gap-y-1 text-caption">
                                <dt className="text-weak">
                                    {t('installation.card.arrived')}
                                </dt>
                                <dd className="m-0" dir="ltr">
                                    {v.arrived_at}
                                </dd>
                                <dt className="text-weak">
                                    {t('installation.card.duration')}
                                </dt>
                                <dd className="m-0">
                                    {v.finished_at
                                        ? duration(v.duration_minutes)
                                        : t('installation.card.running')}
                                </dd>
                            </dl>
                        )}
                        {v.photos.length > 0 && (
                            <div className="grid grid-cols-4 gap-2">
                                {v.photos.map((p) => (
                                    <a
                                        key={p.id}
                                        href={p.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="relative block"
                                    >
                                        <img
                                            src={p.url}
                                            alt={t(
                                                `field.photos.${p.phase}` as TranslationKey,
                                            )}
                                            loading="lazy"
                                            className="aspect-square w-full rounded-md border border-stroke-weak object-cover"
                                        />
                                        <span className="absolute start-1 top-1 rounded-sm bg-surface/90 px-1 text-caption">
                                            {t(
                                                `field.photos.${p.phase}` as TranslationKey,
                                            )}
                                        </span>
                                    </a>
                                ))}
                            </div>
                        )}
                        {v.photos.length === 0 && v.photo_count > 0 && (
                            <p className="m-0 text-caption text-weak">
                                {t('installation.card.photos_hidden', {
                                    count: v.photo_count,
                                })}
                            </p>
                        )}
                        {v.signature && (
                            <div className="flex flex-col gap-1">
                                <p className="m-0 text-caption">
                                    {t('field.signature.done', {
                                        name: v.signature.name ?? '',
                                        at: v.signature.signed_at,
                                    })}{' '}
                                    ·{' '}
                                    {t(
                                        `field.signature.method.${v.signature.method ?? 'device'}` as TranslationKey,
                                    )}
                                </p>
                                {v.signature.url && (
                                    <img
                                        src={v.signature.url}
                                        alt={t('field.signature.image')}
                                        className="max-h-24 max-w-xs rounded-md border border-stroke-weak bg-white object-contain"
                                    />
                                )}
                            </div>
                        )}
                        {v.remarks.length > 0 && (
                            <ul className="m-0 flex list-none flex-col gap-2 p-0">
                                {v.remarks.map((r) => (
                                    <li
                                        key={r.id}
                                        className="rounded-md border border-stroke-weak p-2"
                                    >
                                        <p className="m-0 text-label">
                                            {t(
                                                `installation.remark.kind.${r.kind}` as TranslationKey,
                                            )}{' '}
                                            ·{' '}
                                            {t(
                                                `installation.remark.status.${r.status}` as TranslationKey,
                                            )}
                                        </p>
                                        <p className="m-0 text-body">
                                            {r.description}
                                        </p>
                                        {(r.recorded_by || r.follow_up) && (
                                            <p className="m-0 text-caption text-weak">
                                                {t(
                                                    'installation.remark.people',
                                                    {
                                                        by:
                                                            r.recorded_by ??
                                                            '—',
                                                        follow:
                                                            r.follow_up ?? '—',
                                                    },
                                                )}
                                            </p>
                                        )}
                                        {r.resolution && (
                                            <p className="m-0 text-caption text-weak">
                                                {t(
                                                    'installation.remark.resolved_with',
                                                    {
                                                        text: r.resolution,
                                                    },
                                                )}
                                            </p>
                                        )}
                                        {r.status === 'open' &&
                                            installation.can_resolve && (
                                                <ResolveRemark remark={r} />
                                            )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>
        </Card>
    );
}

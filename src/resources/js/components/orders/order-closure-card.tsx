import { router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/opsone/amount';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { useIdempotencyKey } from '@/components/procurement/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';

export type OrderClosure = {
    closable: boolean;
    can_close: boolean;
    blockers: {
        key: string;
        text: string;
        items: { id: number; note: string | null; description: string }[];
    }[];
    can_remark: boolean;
    notes: { id: number; number: string | null }[];
    remarks: {
        id: number;
        note: string | null;
        description: string;
        status: 'open' | 'resolved';
        recorded_by: string | null;
        recorded_at: string | null;
        follow_up: string | null;
        resolution: string | null;
        resolved_by: string | null;
        resolved_at: string | null;
        lock_version: number;
    }[];
    write_off: WriteOffPanel | null;
};

export type WriteOffPanel = {
    owed_minor: number | null;
    can_write_off: boolean;
    lock_version: number;
    entries: {
        id: number;
        number: string | null;
        kind: 'write_off' | 'reversal';
        reason: string;
        amount_minor: number | null;
        by: string | null;
        at: string;
        reverses_id: number | null;
        files: number;
        can_reverse: boolean;
    }[];
};

/** Debt at closure (P-103, ADR-0034): write off what is still owed, with a reason and an optional file; reversal only by a reversal entry. */
function WriteOffSection({
    orderId,
    panel,
}: {
    orderId: number;
    panel: WriteOffPanel;
}) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();
    const [reason, setReason] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [reverseReason, setReverseReason] = useState<Record<number, string>>(
        {},
    );

    return (
        <div className="mb-4 grid gap-2" data-test="write-off">
            <h3 className="text-label text-strong">
                {t('finance.write_off.title')}
            </h3>
            {panel.entries.length > 0 && (
                <ul className="grid gap-2" data-test="write-off-entries">
                    {panel.entries.map((e) => (
                        <li key={e.id} className="text-caption">
                            <span className="text-weak">
                                <bdi dir="ltr">{e.number}</bdi> ·{' '}
                                {t(`finance.write_off.kind_${e.kind}`)} ·{' '}
                                {formatDate(e.at, locale)} · {e.by ?? '—'}
                            </span>
                            {e.amount_minor !== null && (
                                <p className="text-strong">
                                    <Amount value={e.amount_minor / 100} />
                                </p>
                            )}
                            <p className="text-weak">{e.reason}</p>
                            {e.can_reverse && (
                                <div className="mt-1 grid gap-1">
                                    <TextareaField
                                        label={t(
                                            'finance.write_off.reverse_reason',
                                        )}
                                        value={reverseReason[e.id] ?? ''}
                                        onChange={(ev) =>
                                            setReverseReason({
                                                ...reverseReason,
                                                [e.id]: ev.target.value,
                                            })
                                        }
                                    />
                                    <div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                (
                                                    reverseReason[e.id] ?? ''
                                                ).trim().length < 3
                                            }
                                            onClick={() =>
                                                router.post(
                                                    `/finance/write-offs/${e.id}/reverse`,
                                                    {
                                                        reason: reverseReason[
                                                            e.id
                                                        ],
                                                        idempotency_key: key,
                                                        lock_version:
                                                            panel.lock_version,
                                                    },
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: renew,
                                                    },
                                                )
                                            }
                                            data-test="write-off-reverse"
                                        >
                                            {t('finance.write_off.reverse')}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {panel.can_write_off && panel.owed_minor !== null && (
                <div className="grid gap-2" data-test="write-off-form">
                    <p className="text-caption text-weak">
                        {t('finance.write_off.hint')}{' '}
                        <Amount value={panel.owed_minor / 100} />
                    </p>
                    <TextareaField
                        label={t('finance.write_off.reason')}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        required
                    />
                    <input
                        type="file"
                        accept="image/*,application/pdf"
                        aria-label={t('finance.write_off.file')}
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        className="block w-full text-body file:me-3 file:min-h-11 file:cursor-pointer file:rounded-md file:border file:border-stroke-control file:bg-surface file:px-4 file:text-control"
                    />
                    <div>
                        <Button
                            variant="destructive"
                            disabled={reason.trim().length < 3}
                            onClick={() =>
                                router.post(
                                    `/orders/${orderId}/finance/write-offs`,
                                    {
                                        amount_minor: panel.owed_minor,
                                        reason,
                                        file,
                                        idempotency_key: key,
                                        lock_version: panel.lock_version,
                                    },
                                    {
                                        preserveScroll: true,
                                        forceFormData: true,
                                        onFinish: renew,
                                    },
                                )
                            }
                            data-test="write-off-submit"
                        >
                            {t('finance.write_off.action')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** M-04-T07 / M-05-T10: what blocks the closure, the debt write-off, the delivery remarks (M-14-T04) and the close action. */
export function OrderClosureCard({
    orderId,
    closure,
}: {
    orderId: number;
    closure: OrderClosure;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [reason, setReason] = useState('');
    const [noteId, setNoteId] = useState(String(closure.notes[0]?.id ?? ''));
    const [description, setDescription] = useState('');
    const [resolution, setResolution] = useState<Record<number, string>>({});

    return (
        <Card data-test="order-closure">
            <h2 className="text-heading mb-2 flex items-center gap-2 text-strong">
                <Icon icon={Lock} />
                {t('order.closure.title')}
            </h2>

            {closure.closable && (
                <>
                    <p className="mb-3 text-caption text-weak">
                        {t('order.closure.hint')}
                    </p>
                    {closure.blockers.length > 0 ? (
                        <div className="mb-3" data-test="closure-blockers">
                            <p className="text-label text-strong">
                                {t('order.closure.blockers')}
                            </p>
                            <ul className="ms-4 list-disc text-caption text-weak">
                                {closure.blockers.map((b) => (
                                    <li key={b.key}>
                                        {b.text}
                                        {b.items.length > 0 && (
                                            <ul
                                                className="ms-4 list-[circle]"
                                                data-test="closure-blocker-remarks"
                                            >
                                                {b.items.map((i) => (
                                                    <li key={i.id}>
                                                        <bdi dir="ltr">
                                                            {i.note}
                                                        </bdi>
                                                        : {i.description}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="mb-3 text-caption text-weak">
                            {t('order.closure.ready')}
                        </p>
                    )}
                    {closure.can_close && (
                        <div className="mb-4 grid gap-2">
                            <TextareaField
                                label={t('order.closure.reason')}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                            <div>
                                <Button
                                    disabled={
                                        closure.blockers.length > 0 ||
                                        reason.trim().length < 3
                                    }
                                    onClick={() =>
                                        router.post(
                                            `/orders/${orderId}/close`,
                                            { reason },
                                            { preserveScroll: true },
                                        )
                                    }
                                    data-test="order-close"
                                >
                                    {t('order.closure.action')}
                                </Button>
                            </div>
                        </div>
                    )}
                </>
            )}

            {closure.write_off && (
                <WriteOffSection orderId={orderId} panel={closure.write_off} />
            )}

            {(closure.remarks.length > 0 || closure.can_remark) && (
                <div data-test="delivery-remarks">
                    <h3 className="mb-1 text-label text-strong">
                        {t('delivery.remark.title')}
                    </h3>
                    <ul className="mb-3 grid gap-2">
                        {closure.remarks.map((r) => (
                            <li key={r.id} className="text-caption">
                                <span className="text-weak">
                                    <bdi dir="ltr">{r.note}</bdi> —{' '}
                                    {t(
                                        r.status === 'open'
                                            ? 'delivery.remark.open'
                                            : 'delivery.remark.done',
                                    )}
                                </span>
                                <p className="text-strong">{r.description}</p>
                                <p className="text-weak">
                                    {r.recorded_by ?? '—'}
                                    {r.recorded_at &&
                                        ` · ${formatDate(r.recorded_at, locale)}`}
                                    {r.status === 'open' &&
                                        r.follow_up &&
                                        ` · ${t('delivery.remark.follow_up')}: ${r.follow_up}`}
                                </p>
                                {r.status === 'resolved' && (
                                    <p
                                        className="text-weak"
                                        data-test="remark-resolution"
                                    >
                                        {t('delivery.remark.resolved_by', {
                                            name: r.resolved_by ?? '—',
                                            date: r.resolved_at
                                                ? formatDate(
                                                      r.resolved_at,
                                                      locale,
                                                  )
                                                : '—',
                                        })}
                                        {r.resolution && `: ${r.resolution}`}
                                    </p>
                                )}
                                {r.status === 'open' && closure.can_remark && (
                                    <div className="mt-1 grid gap-1">
                                        <TextareaField
                                            label={t(
                                                'delivery.remark.resolution',
                                            )}
                                            value={resolution[r.id] ?? ''}
                                            onChange={(e) =>
                                                setResolution({
                                                    ...resolution,
                                                    [r.id]: e.target.value,
                                                })
                                            }
                                        />
                                        <div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    (
                                                        resolution[r.id] ?? ''
                                                    ).trim().length < 3
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        `/delivery-remarks/${r.id}/resolve`,
                                                        {
                                                            resolution:
                                                                resolution[
                                                                    r.id
                                                                ],
                                                            lock_version:
                                                                r.lock_version,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('delivery.remark.resolve')}
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    {closure.can_remark && closure.notes.length > 0 && (
                        <div className="grid gap-2">
                            <SelectField
                                label={t('delivery.remark.note')}
                                value={noteId}
                                onChange={(e) => setNoteId(e.target.value)}
                                options={closure.notes.map((n) => ({
                                    value: String(n.id),
                                    label: n.number ?? String(n.id),
                                }))}
                            />
                            <TextareaField
                                label={t('delivery.remark.description')}
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                            />
                            <div>
                                <Button
                                    variant="outline"
                                    disabled={description.trim().length < 3}
                                    onClick={() =>
                                        router.post(
                                            `/deliveries/${noteId}/remarks`,
                                            { description },
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setDescription(''),
                                            },
                                        )
                                    }
                                >
                                    {t('delivery.remark.add')}
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}

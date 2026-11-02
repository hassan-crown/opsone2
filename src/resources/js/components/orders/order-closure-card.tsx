import { router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

export type OrderClosure = {
    closable: boolean;
    can_close: boolean;
    blockers: { key: string; text: string }[];
    can_remark: boolean;
    notes: { id: number; number: string | null }[];
    remarks: {
        id: number;
        note: string | null;
        description: string;
        status: 'open' | 'resolved';
        resolution: string | null;
        lock_version: number;
    }[];
};

/** M-04-T07 / M-05-T10: what blocks the closure, the delivery remarks (M-14-T04) and the close action. */
export function OrderClosureCard({
    orderId,
    closure,
}: {
    orderId: number;
    closure: OrderClosure;
}) {
    const t = useT();
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
                                    <li key={b.key}>{b.text}</li>
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
                                {r.status === 'resolved' && r.resolution && (
                                    <p className="text-weak">{r.resolution}</p>
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

import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useRef, useState } from 'react';
import DeliveryController from '@/actions/App/Http/Controllers/Delivery/DeliveryController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import {
    DeliveryStatusBadge,
    KindLabel,
    QtyText,
} from '@/components/delivery/shared';
import { Amount } from '@/components/opsone/amount';
import { TextField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import {
    BackLink,
    FormErrors,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { DeliveryDetail } from '@/types/delivery';

type Props = { note: DeliveryDetail };

const MAX_PROOFS = 5;

function today(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** DelRecord: the delivery note — record it (receiver, date, proof) or withdraw the draft. */
export default function DeliveryShow({ note }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();
    const fileInput = useRef<HTMLInputElement>(null);
    const [withdrawing, setWithdrawing] = useState(false);
    const amounts = note.lines.some((l) => l.value_minor !== undefined);
    const form = useForm({
        delivered_on: today(),
        receiver_name: '',
        note: note.note ?? '',
        proofs: [] as File[],
    });
    const cancel = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const title =
        note.number ?? t('delivery.draft_title', { order: note.order.number });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            idempotency_key: key,
            lock_version: note.lock_version,
        }));
        form.post(DeliveryController.confirm.url(note.id), {
            preserveScroll: true,
            forceFormData: true,
            onError: renew,
        });
    };

    const addFiles = (list: FileList | null) => {
        if (!list) {
            return;
        }
        form.setData(
            'proofs',
            [...form.data.proofs, ...Array.from(list)].slice(0, MAX_PROOFS),
        );
        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };
    const proofError =
        errors.proofs ??
        Object.entries(errors).find(([k]) => k.startsWith('proofs.'))?.[1];

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={`${note.customer} · ${note.order.number}`}
                back={
                    <BackLink
                        href={DeliveryController.index.url()}
                        label={t('delivery.title')}
                    />
                }
                actions={<DeliveryStatusBadge status={note.status} />}
            />
            <div className="grid gap-4">
                <FormErrors errors={errors} />
                <Card className="gap-3">
                    <dl className="grid gap-2 md:grid-cols-4">
                        <div>
                            <dt className="text-caption text-weak">
                                {t('delivery.col_order')}
                            </dt>
                            <dd>
                                <Link
                                    href={OrderController.show.url(
                                        note.order.id,
                                    )}
                                    className="text-link hover:underline"
                                >
                                    {note.order.number}
                                </Link>{' '}
                                (<KindLabel kind={note.order.kind} />)
                            </dd>
                        </div>
                        {note.status === 'confirmed' && (
                            <>
                                <div>
                                    <dt className="text-caption text-weak">
                                        {t('delivery.col_date')}
                                    </dt>
                                    <dd>
                                        {note.delivered_on
                                            ? formatDate(
                                                  note.delivered_on,
                                                  locale,
                                              )
                                            : '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-weak">
                                        {t('delivery.col_receiver')}
                                    </dt>
                                    <dd data-test="receiver">
                                        {note.receiver_name}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-weak">
                                        {t('delivery.recorded_by')}
                                    </dt>
                                    <dd>{note.confirmed_by ?? '—'}</dd>
                                </div>
                            </>
                        )}
                        {note.status === 'cancelled' && (
                            <div className="md:col-span-3">
                                <dt className="text-caption text-weak">
                                    {t('delivery.cancel_reason')}
                                </dt>
                                <dd>{note.cancel_reason}</dd>
                            </div>
                        )}
                    </dl>
                    {note.note && note.status !== 'draft' && (
                        <p className="text-body text-weak">{note.note}</p>
                    )}
                </Card>

                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('delivery.items')}>
                            <thead>
                                <tr>
                                    <Th>{t('delivery.col_item')}</Th>
                                    <Th>{t('delivery.col_quantity')}</Th>
                                    {note.status === 'draft' && (
                                        <Th>{t('delivery.deliverable')}</Th>
                                    )}
                                    {amounts && (
                                        <Th className="text-end">
                                            {t('delivery.col_value')}
                                        </Th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {note.lines.map((l) => (
                                    <tr
                                        key={l.order_line_id}
                                        data-test="note-line"
                                    >
                                        <Td>{l.name}</Td>
                                        <Td>
                                            <QtyText
                                                value={l.quantity}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        {note.status === 'draft' && (
                                            <Td>
                                                <QtyText
                                                    value={l.deliverable ?? '0'}
                                                    unit={l.unit}
                                                />
                                            </Td>
                                        )}
                                        {amounts && (
                                            <Td className="text-end">
                                                {l.value_minor !== undefined ? (
                                                    <Amount
                                                        value={
                                                            l.value_minor / 100
                                                        }
                                                    />
                                                ) : (
                                                    '—'
                                                )}
                                            </Td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>

                {note.proofs.length > 0 && (
                    <Card className="gap-2" data-test="proofs">
                        <h2 className="text-heading text-strong">
                            {t('delivery.proofs')}
                        </h2>
                        <ul className="grid gap-1">
                            {note.proofs.map((p) => (
                                <li key={p.uuid}>
                                    {p.url ? (
                                        <a
                                            href={p.url}
                                            className="text-link hover:underline"
                                        >
                                            {p.name}
                                        </a>
                                    ) : (
                                        p.name
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {note.can.confirm && (
                    <form
                        onSubmit={submit}
                        className="grid gap-4"
                        data-test="record-form"
                    >
                        <Card className="gap-4">
                            <h2 className="text-heading text-strong">
                                {t('delivery.record_title')}
                            </h2>
                            <div className="grid gap-4 md:grid-cols-2">
                                <TextField
                                    label={t('delivery.receiver')}
                                    maxLength={120}
                                    value={form.data.receiver_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'receiver_name',
                                            e.target.value,
                                        )
                                    }
                                    required
                                    error={errors.receiver_name}
                                />
                                <TextField
                                    label={t('delivery.delivered_on')}
                                    type="date"
                                    dir="ltr"
                                    max={today()}
                                    value={form.data.delivered_on}
                                    onChange={(e) =>
                                        form.setData(
                                            'delivered_on',
                                            e.target.value,
                                        )
                                    }
                                    required
                                    error={errors.delivered_on}
                                />
                            </div>
                            <TextareaField
                                label={t('delivery.note')}
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                                optional
                                error={errors.note}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="proofs">
                                    {t('delivery.proof_input')}
                                </Label>
                                <p className="text-caption text-weak">
                                    {t('delivery.proofs_hint', {
                                        n: MAX_PROOFS,
                                    })}
                                </p>
                                <input
                                    ref={fileInput}
                                    id="proofs"
                                    type="file"
                                    multiple
                                    accept="image/*,application/pdf"
                                    disabled={
                                        form.data.proofs.length >= MAX_PROOFS
                                    }
                                    onChange={(e) => addFiles(e.target.files)}
                                    className="block w-full text-body file:me-3 file:min-h-11 file:cursor-pointer file:rounded-md file:border file:border-stroke-control file:bg-surface file:px-4 file:text-control"
                                    data-test="proofs-input"
                                />
                                {form.data.proofs.length > 0 && (
                                    <ul className="grid gap-1">
                                        {form.data.proofs.map((file, i) => (
                                            <li
                                                key={`${file.name}-${i}`}
                                                className="flex items-center justify-between gap-2 rounded-md bg-fill px-3 py-1"
                                            >
                                                <span className="min-w-0 truncate">
                                                    {file.name}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={t(
                                                        'delivery.remove_file',
                                                    )}
                                                    onClick={() =>
                                                        form.setData(
                                                            'proofs',
                                                            form.data.proofs.filter(
                                                                (_, n) =>
                                                                    n !== i,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Icon icon={X} size="sm" />
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {proofError && (
                                    <p
                                        className="text-caption text-danger-text"
                                        role="alert"
                                    >
                                        {proofError}
                                    </p>
                                )}
                            </div>
                        </Card>
                        <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                            <Button
                                type="submit"
                                loading={form.processing}
                                disabled={form.processing}
                                data-test="record-delivery"
                            >
                                {t('delivery.record_submit')}
                            </Button>
                            {note.can.cancel && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setWithdrawing((v) => !v)}
                                >
                                    {t('delivery.withdraw')}
                                </Button>
                            )}
                        </div>
                    </form>
                )}

                {withdrawing && note.can.cancel && (
                    <Card className="gap-3" data-test="withdraw-form">
                        <TextareaField
                            label={t('delivery.cancel_reason')}
                            value={cancel.data.reason}
                            onChange={(e) =>
                                cancel.setData('reason', e.target.value)
                            }
                            required
                            error={cancel.errors.reason}
                        />
                        <div>
                            <Button
                                type="button"
                                variant="destructive"
                                loading={cancel.processing}
                                disabled={
                                    cancel.processing ||
                                    cancel.data.reason.trim().length < 3
                                }
                                onClick={() =>
                                    cancel.post(
                                        DeliveryController.cancel.url(note.id),
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                {t('delivery.withdraw_confirm')}
                            </Button>
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

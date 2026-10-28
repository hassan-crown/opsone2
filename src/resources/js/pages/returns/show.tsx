import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ReturnController from '@/actions/App/Http/Controllers/Returns/ReturnController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { QtyText } from '@/components/delivery/shared';
import { Amount } from '@/components/opsone/amount';
import {
    CheckboxField,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { minorToInput, parseQty } from '@/components/procurement/quantity';
import {
    BackLink,
    FormErrors,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { OverdueBadge, ReturnStatusBadge } from '@/components/returns/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { ReturnDetail, ReturnPart } from '@/types/returns';

type Props = { return: ReturnDetail };

type Errors = Record<string, string | undefined>;

/** Write options shared by every action: keep the scroll, renew the key after an answer. */
function useWrite() {
    const { key, renew } = useIdempotencyKey();

    return {
        key,
        options: { preserveScroll: true, onError: renew, onSuccess: renew },
    };
}

/** ReturnPage: the return — decision (P-102), receipt and inspection (P-53), withdrawal, correction and refund. */
export default function ReturnShow({ return: r }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [panel, setPanel] = useState<'accept' | 'reject' | 'withdraw' | null>(
        null,
    );
    const amounts = r.settlement !== null;
    const lineName = (id: number | null) =>
        r.lines.find((l) => l.order_line_id === id)?.name ?? '—';
    const lineUnit = (id: number | null) =>
        r.lines.find((l) => l.order_line_id === id)?.unit ?? null;

    return (
        <>
            <Head title={r.number} />
            <PageHeader
                title={r.number}
                description={`${r.customer} · ${r.order.number}`}
                back={
                    <BackLink
                        href={ReturnController.index.url()}
                        label={t('returns.title')}
                    />
                }
                actions={<ReturnStatusBadge status={r.status} />}
            />
            <div className="grid gap-4">
                <Card className="gap-3">
                    <dl className="grid gap-3 md:grid-cols-4">
                        <Info label={t('returns.col_order')}>
                            <Link
                                href={OrderController.show.url(r.order.id)}
                                className="text-link hover:underline"
                            >
                                <bdi dir="ltr">{r.order.number}</bdi>
                            </Link>
                        </Info>
                        <Info label={t('returns.requested_by')}>
                            {r.requested_by ?? '—'} ·{' '}
                            {formatDate(r.created_at, locale)}
                        </Info>
                        <Info label={t('returns.delivered_on')}>
                            <span className="flex flex-wrap items-center gap-2">
                                {r.delivered_on
                                    ? formatDate(r.delivered_on, locale)
                                    : '—'}
                                {r.overdue && (
                                    <OverdueBadge days={r.window_days} />
                                )}
                            </span>
                        </Info>
                        <Info label={t('returns.window_days')}>
                            {t('returns.days', { n: r.window_days })}
                        </Info>
                        <Info label={t('returns.reason')} wide>
                            {r.reason}
                        </Info>
                        {r.decided_by && (
                            <Info label={t('returns.decision')} wide>
                                {r.decision_reason} — {r.decided_by}
                                {r.decided_at
                                    ? ` · ${formatDate(r.decided_at, locale)}`
                                    : ''}
                            </Info>
                        )}
                        {r.cancel_reason && (
                            <Info label={t('returns.withdraw_reason')} wide>
                                {r.cancel_reason}
                            </Info>
                        )}
                    </dl>
                </Card>

                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('returns.items')}>
                            <thead>
                                <tr>
                                    <Th>{t('returns.col_item')}</Th>
                                    <Th>{t('returns.requested')}</Th>
                                    <Th>{t('returns.accepted')}</Th>
                                    <Th>{t('returns.received')}</Th>
                                    <Th>{t('returns.good')}</Th>
                                    <Th>{t('returns.damaged')}</Th>
                                    <Th>{t('returns.rejected')}</Th>
                                    {amounts && (
                                        <Th className="text-end">
                                            {t('returns.col_value')}
                                        </Th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {r.lines.map((l) => (
                                    <tr
                                        key={l.order_line_id}
                                        data-test="return-line"
                                    >
                                        <Td>{l.name}</Td>
                                        <Td>
                                            <QtyText
                                                value={l.requested}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        <Td>
                                            <QtyText value={l.accepted} />
                                        </Td>
                                        <Td>
                                            <QtyText value={l.received} />
                                        </Td>
                                        <Td>
                                            <QtyText value={l.good} />
                                        </Td>
                                        <Td>
                                            <QtyText value={l.damaged} />
                                        </Td>
                                        <Td>
                                            <QtyText value={l.rejected} />
                                        </Td>
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

                {r.parts.length > 0 && (
                    <Card className="gap-3" data-test="parts">
                        <h2 className="text-heading text-strong">
                            {t('returns.parts')}
                        </h2>
                        <ul className="grid gap-3">
                            {r.parts.map((p) => (
                                <PartRow
                                    key={p.id}
                                    part={p}
                                    name={lineName(p.order_line_id)}
                                    unit={lineUnit(p.order_line_id)}
                                    detail={r}
                                    canInspect={r.can.inspect && !p.inspected}
                                />
                            ))}
                        </ul>
                    </Card>
                )}

                {r.can.receive && <ReceiveForm detail={r} />}

                {amounts && <SettlementCard detail={r} />}

                {(r.can.accept || r.can.reject || r.can.withdraw) && (
                    <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                        {r.can.accept && (
                            <Button
                                onClick={() => setPanel('accept')}
                                data-test="open-accept"
                            >
                                {t('returns.accept')}
                            </Button>
                        )}
                        {r.can.reject && (
                            <Button
                                variant="secondary"
                                onClick={() => setPanel('reject')}
                            >
                                {t('returns.reject')}
                            </Button>
                        )}
                        {r.can.withdraw && (
                            <Button
                                variant="secondary"
                                onClick={() => setPanel('withdraw')}
                            >
                                {t('returns.withdraw')}
                            </Button>
                        )}
                    </div>
                )}
                {panel === 'accept' && r.can.accept && (
                    <AcceptForm detail={r} />
                )}
                {panel === 'reject' && r.can.reject && (
                    <ReasonForm
                        detail={r}
                        url={ReturnController.reject.url(r.id)}
                        label={t('returns.reject_reason')}
                        submit={t('returns.reject_confirm')}
                    />
                )}
                {panel === 'withdraw' && r.can.withdraw && (
                    <ReasonForm
                        detail={r}
                        url={ReturnController.withdraw.url(r.id)}
                        label={t('returns.withdraw_reason')}
                        submit={t(
                            r.status === 'requested'
                                ? 'returns.withdraw_confirm'
                                : 'returns.release_confirm',
                        )}
                    />
                )}
            </div>
        </>
    );
}

function Info({
    label,
    wide,
    children,
}: {
    label: string;
    wide?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className={wide ? 'md:col-span-4' : undefined}>
            <dt className="text-caption text-weak">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function AcceptForm({ detail }: { detail: ReturnDetail }) {
    const t = useT();
    const { key, options } = useWrite();
    const form = useForm({
        reason: '',
        settlement: '',
        quantities: detail.lines.map((l) => l.requested),
    });
    const errors = form.errors as Errors;

    return (
        <Card className="gap-4" data-test="accept-form">
            <h2 className="text-heading text-strong">
                {t('returns.accept_title')}
            </h2>
            <FormErrors errors={errors} />
            <ul className="grid gap-3">
                {detail.lines.map((l, i) => (
                    <li
                        key={l.order_line_id}
                        className="grid gap-2 md:grid-cols-[1fr_12rem]"
                    >
                        <p className="text-body">
                            {l.name}
                            <span className="block text-caption text-weak">
                                {t('returns.requested')}{' '}
                                <QtyText value={l.requested} unit={l.unit} />
                                {l.eligible !== null && (
                                    <>
                                        {' · '}
                                        {t('returns.eligible_qty')}{' '}
                                        <QtyText
                                            value={l.eligible}
                                            unit={l.unit}
                                        />
                                    </>
                                )}
                            </span>
                        </p>
                        <TextField
                            label={t('returns.accepted')}
                            inputMode="decimal"
                            dir="ltr"
                            value={form.data.quantities[i]}
                            onChange={(e) =>
                                form.setData(
                                    'quantities',
                                    form.data.quantities.map((q, n) =>
                                        n === i ? e.target.value : q,
                                    ),
                                )
                            }
                            error={
                                errors[`lines.${i}.quantity`] ??
                                (parseQty(form.data.quantities[i]) === null
                                    ? t('returns.error.quantity')
                                    : undefined)
                            }
                        />
                    </li>
                ))}
            </ul>
            {detail.settlement !== null && (
                <TextField
                    label={t('returns.settlement')}
                    hint={t('returns.settlement_hint')}
                    inputMode="decimal"
                    dir="ltr"
                    optional
                    value={form.data.settlement}
                    onChange={(e) => form.setData('settlement', e.target.value)}
                    error={errors.settlement}
                />
            )}
            <TextareaField
                label={t('returns.decision_reason')}
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
                required
                error={errors.reason}
            />
            <div>
                <Button
                    loading={form.processing}
                    disabled={
                        form.processing || form.data.reason.trim().length < 3
                    }
                    data-test="accept-return"
                    onClick={() => {
                        form.transform((data) => ({
                            reason: data.reason,
                            settlement: data.settlement.trim() || null,
                            lines: detail.lines.map((l, i) => ({
                                order_line_id: l.order_line_id,
                                quantity: data.quantities[i].trim(),
                            })),
                            idempotency_key: key,
                            lock_version: detail.lock_version,
                        }));
                        form.post(
                            ReturnController.accept.url(detail.id),
                            options,
                        );
                    }}
                >
                    {t('returns.accept_confirm')}
                </Button>
            </div>
        </Card>
    );
}

function ReasonForm({
    detail,
    url,
    label,
    submit,
}: {
    detail: ReturnDetail;
    url: string;
    label: string;
    submit: string;
}) {
    const { key, options } = useWrite();
    const form = useForm({ reason: '' });

    return (
        <Card className="gap-3" data-test="reason-form">
            <FormErrors errors={form.errors as Errors} />
            <TextareaField
                label={label}
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
                required
                error={form.errors.reason}
            />
            <div>
                <Button
                    variant="destructive"
                    loading={form.processing}
                    disabled={
                        form.processing || form.data.reason.trim().length < 3
                    }
                    onClick={() => {
                        form.transform((data) => ({
                            ...data,
                            idempotency_key: key,
                            lock_version: detail.lock_version,
                        }));
                        form.post(url, options);
                    }}
                >
                    {submit}
                </Button>
            </div>
        </Card>
    );
}

function ReceiveForm({ detail }: { detail: ReturnDetail }) {
    const t = useT();
    const { key, options } = useWrite();
    const expected = detail.lines.filter((l) => l.outstanding !== '0');
    const form = useForm({
        location_id: String(detail.locations[0]?.id ?? ''),
        inspect: true,
        note: '',
        rows: expected.map((l) => ({
            quantity: l.outstanding,
            good: l.outstanding,
            damaged: '0',
            rejected: '0',
        })),
    });
    const errors = form.errors as Errors;
    const setRow = (i: number, field: string, value: string) =>
        form.setData(
            'rows',
            form.data.rows.map((row, n) =>
                n === i ? { ...row, [field]: value } : row,
            ),
        );

    return (
        <Card className="gap-4" data-test="receive-form">
            <h2 className="text-heading text-strong">
                {t('returns.receive_title')}
            </h2>
            <FormErrors errors={errors} />
            {detail.locations.length === 0 ? (
                <p className="text-body text-weak">
                    {t('returns.no_location')}
                </p>
            ) : (
                <SelectField
                    label={t('returns.location')}
                    value={form.data.location_id}
                    onChange={(e) =>
                        form.setData('location_id', e.target.value)
                    }
                    options={detail.locations.map((l) => ({
                        value: String(l.id),
                        label: `${l.code} — ${l.name ?? ''}`,
                    }))}
                    error={errors.location_id}
                />
            )}
            <CheckboxField
                label={t('returns.inspect_now')}
                hint={t('returns.inspect_now_hint')}
                checked={form.data.inspect}
                onChange={(e) => form.setData('inspect', e.target.checked)}
            />
            <ul className="grid gap-3">
                {expected.map((l, i) => (
                    <li
                        key={l.order_line_id}
                        className="grid gap-3 rounded-md border border-stroke-weak p-3"
                    >
                        <p className="font-semibold text-strong">
                            {l.name}
                            <span className="block text-caption font-normal text-weak">
                                {t('returns.outstanding')}{' '}
                                <QtyText value={l.outstanding} unit={l.unit} />
                            </span>
                        </p>
                        <div className="grid gap-3 md:grid-cols-4">
                            <QtyField
                                label={t('returns.received_now')}
                                value={form.data.rows[i].quantity}
                                onChange={(v) => setRow(i, 'quantity', v)}
                                error={errors[`lines.${i}.quantity`]}
                            />
                            {form.data.inspect &&
                                (['good', 'damaged', 'rejected'] as const).map(
                                    (f) => (
                                        <QtyField
                                            key={f}
                                            label={t(
                                                `returns.${f}` as TranslationKey,
                                            )}
                                            value={form.data.rows[i][f]}
                                            onChange={(v) => setRow(i, f, v)}
                                            error={errors[`lines.${i}.${f}`]}
                                        />
                                    ),
                                )}
                        </div>
                    </li>
                ))}
            </ul>
            <TextareaField
                label={t('returns.note')}
                value={form.data.note}
                onChange={(e) => form.setData('note', e.target.value)}
                optional
            />
            <div>
                <Button
                    loading={form.processing}
                    disabled={form.processing || form.data.location_id === ''}
                    data-test="receive-return"
                    onClick={() => {
                        form.transform((data) => ({
                            location_id: Number(data.location_id),
                            inspect: data.inspect,
                            note: data.note,
                            lines: expected.map((l, i) => ({
                                order_line_id: l.order_line_id,
                                quantity: data.rows[i].quantity.trim(),
                                ...(data.inspect
                                    ? {
                                          good: data.rows[i].good.trim(),
                                          damaged: data.rows[i].damaged.trim(),
                                          rejected:
                                              data.rows[i].rejected.trim(),
                                      }
                                    : {}),
                            })),
                            idempotency_key: key,
                            lock_version: detail.lock_version,
                        }));
                        form.post(
                            ReturnController.receive.url(detail.id),
                            options,
                        );
                    }}
                >
                    {t(
                        form.data.inspect
                            ? 'returns.receive_inspect_submit'
                            : 'returns.receive_submit',
                    )}
                </Button>
            </div>
        </Card>
    );
}

function QtyField({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    const t = useT();

    return (
        <TextField
            label={label}
            inputMode="decimal"
            dir="ltr"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            error={
                error ??
                (parseQty(value) === null
                    ? t('returns.error.quantity')
                    : undefined)
            }
        />
    );
}

function PartRow({
    part,
    name,
    unit,
    detail,
    canInspect,
}: {
    part: ReturnPart;
    name: string;
    unit: string | null;
    detail: ReturnDetail;
    canInspect: boolean;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [correcting, setCorrecting] = useState(false);

    return (
        <li
            className="grid gap-2 rounded-md border border-stroke-weak p-3"
            data-test="return-part"
        >
            <p className="text-body">
                <span className="font-semibold text-strong">{name}</span> ·{' '}
                <QtyText value={part.quantity} unit={unit} /> ·{' '}
                {part.location.code} · {formatDate(part.received_at, locale)}
                {part.received_by ? ` · ${part.received_by}` : ''}
            </p>
            {part.inspected ? (
                <p className="text-caption text-weak">
                    {t('returns.good')} <QtyText value={part.good ?? '0'} /> ·{' '}
                    {t('returns.damaged')}{' '}
                    <QtyText value={part.damaged ?? '0'} /> ·{' '}
                    {t('returns.rejected')}{' '}
                    <QtyText value={part.rejected ?? '0'} />
                    {part.inspection_note ? ` — ${part.inspection_note}` : ''}
                </p>
            ) : (
                <p className="text-caption text-warning-text">
                    {t('returns.awaiting_inspection')}
                </p>
            )}
            {canInspect && <InspectForm part={part} detail={detail} />}
            {part.can_correct && (
                <div>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setCorrecting((v) => !v)}
                    >
                        {t('returns.correct')}
                    </Button>
                </div>
            )}
            {correcting && part.can_correct && (
                <CorrectForm part={part} detail={detail} />
            )}
        </li>
    );
}

function InspectForm({
    part,
    detail,
}: {
    part: ReturnPart;
    detail: ReturnDetail;
}) {
    const t = useT();
    const { key, options } = useWrite();
    const form = useForm({
        good: part.quantity,
        damaged: '0',
        rejected: '0',
        note: '',
    });
    const errors = form.errors as Errors;

    return (
        <div className="grid gap-3" data-test="inspect-form">
            <FormErrors errors={errors} />
            <div className="grid gap-3 md:grid-cols-3">
                {(['good', 'damaged', 'rejected'] as const).map((f) => (
                    <QtyField
                        key={f}
                        label={t(`returns.${f}` as TranslationKey)}
                        value={form.data[f]}
                        onChange={(v) => form.setData(f, v)}
                        error={errors['results.0']}
                    />
                ))}
            </div>
            <TextField
                label={t('returns.note')}
                value={form.data.note}
                onChange={(e) => form.setData('note', e.target.value)}
                optional
            />
            <div>
                <Button
                    size="sm"
                    loading={form.processing}
                    disabled={form.processing}
                    data-test="inspect-part"
                    onClick={() => {
                        form.transform((data) => ({
                            note: data.note,
                            results: [
                                {
                                    part_id: part.id,
                                    good: data.good.trim(),
                                    damaged: data.damaged.trim(),
                                    rejected: data.rejected.trim(),
                                },
                            ],
                            idempotency_key: key,
                            lock_version: detail.lock_version,
                        }));
                        form.post(
                            ReturnController.inspect.url(detail.id),
                            options,
                        );
                    }}
                >
                    {t('returns.inspect_submit')}
                </Button>
            </div>
        </div>
    );
}

function CorrectForm({
    part,
    detail,
}: {
    part: ReturnPart;
    detail: ReturnDetail;
}) {
    const t = useT();
    const { key, options } = useWrite();
    const form = useForm({ good: part.good ?? '0', reason: '' });
    const errors = form.errors as Errors;

    return (
        <div className="grid gap-3" data-test="correct-form">
            <FormErrors errors={errors} />
            <QtyField
                label={t('returns.corrected_good')}
                value={form.data.good}
                onChange={(v) => form.setData('good', v)}
                error={errors.good}
            />
            <TextareaField
                label={t('returns.correct_reason')}
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
                required
                error={errors.reason}
            />
            <div>
                <Button
                    size="sm"
                    variant="secondary"
                    loading={form.processing}
                    disabled={
                        form.processing || form.data.reason.trim().length < 3
                    }
                    onClick={() => {
                        form.transform((data) => ({
                            part_id: part.id,
                            good: data.good.trim(),
                            reason: data.reason,
                            idempotency_key: key,
                            lock_version: detail.lock_version,
                        }));
                        form.post(
                            ReturnController.correct.url(detail.id),
                            options,
                        );
                    }}
                >
                    {t('returns.correct_submit')}
                </Button>
            </div>
        </div>
    );
}

function SettlementCard({ detail }: { detail: ReturnDetail }) {
    const t = useT();
    const { key, options } = useWrite();
    const settlement = detail.settlement;
    const form = useForm({
        payment_id: String(detail.payments[0]?.id ?? ''),
        amount:
            settlement?.settlement_minor != null
                ? minorToInput(settlement.settlement_minor)
                : '',
        method: 'transfer',
        reason: '',
    });
    const errors = form.errors as Errors;

    return (
        <Card className="gap-3" data-test="settlement">
            <h2 className="text-heading text-strong">
                {t('returns.settlement_title')}
            </h2>
            <dl className="grid gap-2 md:grid-cols-3">
                <Info label={t('returns.value')}>
                    {settlement?.value_minor != null ? (
                        <Amount value={settlement.value_minor / 100} />
                    ) : (
                        '—'
                    )}
                </Info>
                <Info label={t('returns.settlement')}>
                    {settlement?.settlement_minor != null ? (
                        <Amount value={settlement.settlement_minor / 100} />
                    ) : (
                        '—'
                    )}
                </Info>
                <Info label={t('returns.refund')}>
                    {detail.refund ? (
                        <>
                            {detail.refund.number ?? '—'} ·{' '}
                            {t(
                                `refunds.status.${detail.refund.status}` as TranslationKey,
                            )}
                            {detail.refund.amount_minor !== undefined && (
                                <>
                                    {' · '}
                                    <Amount
                                        value={detail.refund.amount_minor / 100}
                                    />
                                </>
                            )}
                        </>
                    ) : (
                        t('returns.no_refund')
                    )}
                </Info>
            </dl>
            <p className="text-caption text-weak">{t('returns.money_hint')}</p>
            {detail.can.refund &&
                (detail.payments.length === 0 ? (
                    <p className="text-body text-weak">
                        {t('returns.no_payments')}
                    </p>
                ) : (
                    <div className="grid gap-3" data-test="refund-form">
                        <FormErrors errors={errors} />
                        <div className="grid gap-3 md:grid-cols-3">
                            <SelectField
                                label={t('returns.payment')}
                                value={form.data.payment_id}
                                onChange={(e) =>
                                    form.setData('payment_id', e.target.value)
                                }
                                options={detail.payments.map((p) => ({
                                    value: String(p.id),
                                    label: `${p.receipt_number ?? `#${p.id}`} — ${minorToInput(p.refundable_minor)}`,
                                }))}
                            />
                            <TextField
                                label={t('returns.refund_amount')}
                                inputMode="decimal"
                                dir="ltr"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData('amount', e.target.value)
                                }
                                error={errors.amount}
                            />
                            <SelectField
                                label={t('returns.method')}
                                value={form.data.method}
                                onChange={(e) =>
                                    form.setData('method', e.target.value)
                                }
                                options={(
                                    [
                                        'transfer',
                                        'cash',
                                        'card',
                                        'cheque',
                                    ] as const
                                ).map((m) => ({
                                    value: m,
                                    label: t(
                                        `finance.method.${m}` as TranslationKey,
                                    ),
                                }))}
                            />
                        </div>
                        <TextareaField
                            label={t('returns.refund_reason')}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            required
                            error={errors.reason}
                        />
                        <div>
                            <Button
                                loading={form.processing}
                                disabled={
                                    form.processing ||
                                    form.data.reason.trim().length < 3
                                }
                                data-test="request-refund"
                                onClick={() => {
                                    form.transform((data) => ({
                                        ...data,
                                        payment_id: Number(data.payment_id),
                                        idempotency_key: key,
                                        lock_version: detail.lock_version,
                                    }));
                                    form.post(
                                        ReturnController.refund.url(detail.id),
                                        options,
                                    );
                                }}
                            >
                                {t('returns.refund_submit')}
                            </Button>
                        </div>
                    </div>
                ))}
        </Card>
    );
}

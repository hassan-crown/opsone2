import { Head, router } from '@inertiajs/react';
import { Ban, CircleCheck, CircleStop, HandCoins } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import CancellationController from '@/actions/App/Http/Controllers/Cancellation/CancellationController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { Amount } from '@/components/opsone/amount';
import {
    RadioGroup,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import {
    BackLink,
    FormErrors,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { formatQty, parseQty } from '@/components/procurement/quantity';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Line = {
    ref: string;
    description: string;
    unit: string | null;
    ordered_milli: number;
    delivered_milli: number;
    cancelled_milli: number;
    cancellable_milli: number;
    whole_only: boolean;
    unit_price_minor?: number | null;
};

type Request = {
    id: number;
    number: string;
    scope: 'full' | 'partial';
    status: 'pending' | 'accepted' | 'rejected' | 'withdrawn';
    source: string;
    channel: string | null;
    requested_on: string;
    reason: string;
    decision_reason: string | null;
    requested_by: string | null;
    decided_by: string | null;
    decided_at: string | null;
    lock_version: number;
    applied: {
        status_after: string | null;
        refund_minor?: number | null;
        lines: { ref: string; description: string; quantity_milli: number }[];
    } | null;
};

type SettlementItem = {
    id: number;
    kind: string;
    status: 'open' | 'done' | 'dropped';
    resolution: string | null;
    note: string | null;
    details: Record<string, unknown>;
    target_minor?: number | null;
    covered_minor?: number | null;
    resolved_at: string | null;
    resolver: string | null;
    lock_version: number;
    can_resolve: boolean;
};

type FollowUp = {
    id: number;
    status: 'open' | 'closed';
    closed_at: string | null;
    items: SettlementItem[];
};

type Impact = {
    order: { status: string; status_before: string; status_after: string };
    scope: 'full' | 'partial';
    lines: {
        ref: string;
        description: string;
        unit: string | null;
        quantity_milli: number;
        value_minor?: number | null;
    }[];
    stops: {
        tasks: { id: number; title: string; assignee: string | null }[];
        appointments: { id: number; kind: string; starts_at: string }[];
        stages: { id: number; name: string }[];
        change: { number: string } | null;
    };
    stays: {
        delivered: {
            ref: string;
            description: string;
            unit: string | null;
            delivered_milli: number;
        }[];
        issued: {
            number: string | null;
            item: string;
            unit: string | null;
            issued_milli: number;
        }[];
        stages: { id: number; name: string }[];
        collected_minor?: number;
    };
    settlement: {
        money: {
            collected_minor?: number;
            value_before_minor?: number | null;
            value_after_minor?: number | null;
            pending_refunds_minor?: number;
            refund_minor?: number;
        };
        agreement: {
            version_after: number;
            total_minor?: number;
            dues: {
                reference: string;
                name: string;
                amount_before_minor?: number;
                amount_after_minor?: number;
            }[];
        } | null;
        stock: {
            reservation_id: number;
            number: string;
            item: string;
            location: string;
            release_milli: number;
        }[];
        purchases: {
            id: number;
            number: string;
            supplier: string;
            lines: { item: string; unit: string; remaining_milli: number }[];
        }[];
        commission: {
            user: string;
            accrued_minor?: number;
            target_minor?: number;
            difference_minor?: number;
        } | null;
    };
};

type Props = {
    order: {
        id: number;
        number: string;
        kind: 'custom' | 'ready';
        status: string;
        customer: string;
        lock_version: number;
    };
    amounts_visible: boolean;
    lines: Line[];
    pending: Request | null;
    history: Request[];
    settlement: FollowUp | null;
    channels: string[];
    can: { review: boolean; amounts: boolean };
};

type Preview = { impact: Impact; hash: string; lock_version: number };

function today(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function Money({ minor }: { minor?: number | null }) {
    const t = useT();

    return minor === undefined || minor === null ? (
        <span className="text-weak">{t('cancel.hidden')}</span>
    ) : (
        <Amount value={minor / 100} />
    );
}

function Section({
    icon,
    title,
    children,
    test,
}: {
    icon: typeof Ban;
    title: string;
    children: React.ReactNode;
    test: string;
}) {
    return (
        <Card className="gap-3" data-test={test}>
            <h2 className="text-heading flex items-center gap-2 text-strong">
                <Icon icon={icon} />
                {title}
            </h2>
            {children}
        </Card>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="text-body text-weak">{children}</p>;
}

/** CancelReview (screen 115): review a cancellation's impact on work, stock and money, then confirm; follow the settlement. */
export default function OrderCancel({
    order,
    amounts_visible,
    lines,
    pending,
    history,
    settlement,
    channels,
    can,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();
    const [scope, setScope] = useState<'full' | 'partial'>(
        pending?.scope ?? 'full',
    );
    const [quantities, setQuantities] = useState<Record<string, string>>({});
    const [retained, setRetained] = useState('');
    const [channel, setChannel] = useState(pending?.channel ?? 'phone');
    const [requestedOn, setRequestedOn] = useState(
        pending?.requested_on ?? today(),
    );
    const [reason, setReason] = useState(pending?.reason ?? '');
    const [rejectReason, setRejectReason] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState<string | null>(null);

    const body = () => ({
        scope,
        lines:
            scope === 'partial'
                ? Object.entries(quantities)
                      .filter(([, q]) => (parseQty(q) ?? 0) > 0)
                      .map(([ref, quantity]) => ({ ref, quantity }))
                : [],
        retained: scope === 'full' && retained !== '' ? retained : null,
        source: 'external',
        channel,
        requested_on: requestedOn,
        reason,
    });

    const fail = (result: {
        errors: Record<string, string[]>;
        stale: boolean;
        message: string | null;
        body: Record<string, unknown> | null;
    }) => {
        setErrors(
            Object.fromEntries(
                Object.entries(result.errors).map(([k, v]) => [k, v[0]]),
            ),
        );
        if (result.stale || result.body?.condition === 'preview_stale') {
            setPreview(null);
            setNotice(t('cancel.stale'));
        } else if (typeof result.body?.condition === 'string') {
            setNotice(
                t(
                    `cancel.condition.${result.body.condition}` as TranslationKey,
                ),
            );
        } else if (result.message && Object.keys(result.errors).length === 0) {
            setNotice(result.message);
        }
    };

    const review = async () => {
        setBusy(true);
        setErrors({});
        setNotice(null);
        const result = await api<Preview>(
            'POST',
            CancellationController.preview.url(order.id),
            body(),
        );
        setBusy(false);
        if (result.ok && result.data) {
            setPreview(result.data);
        } else {
            setPreview(null);
            fail(result);
        }
    };

    const write = async (url: string, payload: Record<string, unknown>) => {
        setBusy(true);
        setErrors({});
        setNotice(null);
        const result = await api('POST', url, {
            ...payload,
            idempotency_key: key,
        });
        setBusy(false);
        if (result.ok) {
            renew();
            setPreview(null);
            toast.success(t('cancel.saved'));
            router.reload();
        } else {
            renew();
            fail(result);
        }
    };

    const confirm = () =>
        preview &&
        write(
            pending
                ? CancellationController.accept.url(pending.id)
                : CancellationController.store.url(order.id),
            {
                ...body(),
                hash: preview.hash,
                lock_version: preview.lock_version,
            },
        );

    const fieldErrors = errors as Record<string, string | undefined>;
    const impact = preview?.impact;
    const statusLabel = (status: string) =>
        t(`orders.status.${status}` as TranslationKey);

    return (
        <>
            <Head title={t('cancel.title')} />
            <PageHeader
                title={t('cancel.title')}
                description={`${order.number} · ${order.customer}`}
                back={
                    <BackLink
                        href={OrderController.show.url(order.id)}
                        label={order.number}
                    />
                }
                actions={
                    pending && (
                        <Badge variant="warning">
                            {t('cancel.status.pending')}
                        </Badge>
                    )
                }
            />
            <div className="grid gap-4">
                {notice && (
                    <p
                        className="rounded-md bg-warning-bg px-4 py-3 text-warning-text"
                        role="alert"
                        data-test="cancel-notice"
                    >
                        {notice}
                    </p>
                )}
                <FormErrors errors={fieldErrors} />

                {pending && (
                    <Card className="gap-2" data-test="pending-request">
                        <h2 className="text-heading text-strong">
                            {t('cancel.pending_title', {
                                number: pending.number,
                            })}
                        </h2>
                        <p className="text-body">«{pending.reason}»</p>
                        <p className="text-caption text-weak">
                            {t('cancel.requested_meta', {
                                date: formatDate(pending.requested_on, locale),
                                by: pending.requested_by ?? '—',
                            })}
                        </p>
                        <p className="text-caption text-weak">
                            {t('cancel.pending_hint')}
                        </p>
                    </Card>
                )}

                {can.review && (
                    <Card className="gap-4" data-test="cancel-form">
                        <RadioGroup
                            legend={t('cancel.scope')}
                            name="scope"
                            inline
                            value={scope}
                            onChange={(v) => {
                                setScope(v as 'full' | 'partial');
                                setPreview(null);
                            }}
                            options={[
                                {
                                    value: 'full',
                                    label: t('cancel.scope_full'),
                                },
                                {
                                    value: 'partial',
                                    label: t('cancel.scope_partial'),
                                },
                            ]}
                        />
                        {scope === 'partial' && (
                            <TableWrap>
                                <Table caption={t('cancel.lines')}>
                                    <thead>
                                        <tr>
                                            <Th>{t('cancel.col_item')}</Th>
                                            <Th>{t('cancel.col_ordered')}</Th>
                                            <Th>{t('cancel.col_delivered')}</Th>
                                            <Th>
                                                {t('cancel.col_cancellable')}
                                            </Th>
                                            <Th>{t('cancel.col_cancel')}</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.map((l) => (
                                            <tr
                                                key={l.ref}
                                                data-test="cancel-line"
                                            >
                                                <Td>{l.description}</Td>
                                                <Td>
                                                    <bdi dir="ltr">
                                                        {formatQty(
                                                            l.ordered_milli,
                                                        )}
                                                    </bdi>
                                                </Td>
                                                <Td>
                                                    <bdi dir="ltr">
                                                        {formatQty(
                                                            l.delivered_milli,
                                                        )}
                                                    </bdi>
                                                </Td>
                                                <Td>
                                                    <bdi dir="ltr">
                                                        {formatQty(
                                                            l.cancellable_milli,
                                                        )}
                                                    </bdi>
                                                </Td>
                                                <Td>
                                                    <input
                                                        aria-label={t(
                                                            'cancel.col_cancel',
                                                        )}
                                                        inputMode="decimal"
                                                        dir="ltr"
                                                        className="field-control w-28 text-control"
                                                        disabled={
                                                            l.cancellable_milli ===
                                                            0
                                                        }
                                                        value={
                                                            quantities[l.ref] ??
                                                            ''
                                                        }
                                                        placeholder={
                                                            l.whole_only
                                                                ? t(
                                                                      'cancel.whole_only',
                                                                  )
                                                                : '0'
                                                        }
                                                        onChange={(e) => {
                                                            setPreview(null);
                                                            setQuantities({
                                                                ...quantities,
                                                                [l.ref]:
                                                                    e.target
                                                                        .value,
                                                            });
                                                        }}
                                                    />
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </TableWrap>
                        )}
                        {scope === 'full' && can.amounts && (
                            <TextField
                                label={t('cancel.retained')}
                                hint={t('cancel.retained_hint')}
                                inputMode="decimal"
                                dir="ltr"
                                value={retained}
                                onChange={(e) => {
                                    setRetained(e.target.value);
                                    setPreview(null);
                                }}
                                optional
                                error={fieldErrors.retained}
                            />
                        )}
                        {!pending && (
                            <div className="grid gap-4 md:grid-cols-2">
                                <SelectField
                                    label={t('cancel.channel')}
                                    value={channel}
                                    onChange={(e) => setChannel(e.target.value)}
                                    options={channels.map((c) => ({
                                        value: c,
                                        label: t(
                                            `cancel.channel_${c}` as TranslationKey,
                                        ),
                                    }))}
                                    required
                                    error={fieldErrors.channel}
                                />
                                <TextField
                                    label={t('cancel.requested_on')}
                                    type="date"
                                    dir="ltr"
                                    max={today()}
                                    value={requestedOn}
                                    onChange={(e) =>
                                        setRequestedOn(e.target.value)
                                    }
                                    required
                                    error={fieldErrors.requested_on}
                                />
                            </div>
                        )}
                        {!pending && (
                            <TextareaField
                                label={t('cancel.reason')}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                required
                                error={fieldErrors.reason}
                            />
                        )}
                        <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                            <Button
                                type="button"
                                onClick={review}
                                loading={busy && !preview}
                                disabled={busy}
                                data-test="cancel-preview"
                            >
                                {t('cancel.preview')}
                            </Button>
                            {!pending && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() =>
                                        write(
                                            CancellationController.request.url(
                                                order.id,
                                            ),
                                            {
                                                ...body(),
                                                lock_version:
                                                    order.lock_version,
                                            },
                                        )
                                    }
                                    data-test="cancel-record"
                                >
                                    {t('cancel.record_only')}
                                </Button>
                            )}
                        </div>
                    </Card>
                )}

                {impact && (
                    <div className="grid gap-4" data-test="cancel-impact">
                        <p className="text-body text-strong">
                            {t('cancel.status_change', {
                                from: statusLabel(impact.order.status),
                                to: statusLabel(impact.order.status_after),
                            })}
                        </p>
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Section
                                icon={CircleStop}
                                title={t('cancel.stops')}
                                test="impact-stops"
                            >
                                {impact.lines.length > 0 && (
                                    <ul className="grid gap-1">
                                        {impact.lines.map((l) => (
                                            <li key={l.ref}>
                                                {l.description}:{' '}
                                                <bdi dir="ltr">
                                                    {formatQty(
                                                        l.quantity_milli,
                                                    )}
                                                </bdi>
                                                {l.value_minor !==
                                                    undefined && (
                                                    <>
                                                        {' · '}
                                                        <Money
                                                            minor={
                                                                l.value_minor
                                                            }
                                                        />
                                                    </>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="text-body">
                                    {t('cancel.stops_tasks', {
                                        n: impact.stops.tasks.length,
                                    })}
                                </p>
                                {impact.stops.tasks.length > 0 && (
                                    <ul className="grid gap-1 text-caption text-weak">
                                        {impact.stops.tasks.map((task) => (
                                            <li key={task.id}>
                                                {task.title}
                                                {task.assignee
                                                    ? ` — ${task.assignee}`
                                                    : ''}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="text-body">
                                    {t('cancel.stops_appointments', {
                                        n: impact.stops.appointments.length,
                                    })}
                                </p>
                                {impact.stops.stages.length > 0 && (
                                    <p className="text-caption text-weak">
                                        {t('cancel.stops_stages', {
                                            names: impact.stops.stages
                                                .map((s) => s.name)
                                                .join('، '),
                                        })}
                                    </p>
                                )}
                                {impact.stops.change && (
                                    <p className="text-caption text-warning-text">
                                        {t('cancel.open_change', {
                                            number: impact.stops.change.number,
                                        })}
                                    </p>
                                )}
                            </Section>
                            <Section
                                icon={CircleCheck}
                                title={t('cancel.stays')}
                                test="impact-stays"
                            >
                                {impact.stays.delivered.length === 0 &&
                                impact.stays.issued.length === 0 &&
                                impact.stays.stages.length === 0 ? (
                                    <Empty>{t('cancel.stays_none')}</Empty>
                                ) : (
                                    <ul className="grid gap-1">
                                        {impact.stays.delivered.map((d) => (
                                            <li key={d.ref}>
                                                {t('cancel.stays_delivered', {
                                                    item: d.description,
                                                })}{' '}
                                                <bdi dir="ltr">
                                                    {formatQty(
                                                        d.delivered_milli,
                                                    )}
                                                </bdi>
                                            </li>
                                        ))}
                                        {impact.stays.issued.map((i) => (
                                            <li key={`${i.number}-${i.item}`}>
                                                {t('cancel.stays_issued', {
                                                    item: i.item,
                                                })}{' '}
                                                <bdi dir="ltr">
                                                    {formatQty(i.issued_milli)}
                                                </bdi>
                                            </li>
                                        ))}
                                        {impact.stays.stages.map((s) => (
                                            <li key={s.id}>
                                                {t('cancel.stays_stage', {
                                                    name: s.name,
                                                })}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="text-body">
                                    {t('cancel.collected')}{' '}
                                    <Money
                                        minor={impact.stays.collected_minor}
                                    />
                                </p>
                            </Section>
                            <Section
                                icon={HandCoins}
                                title={t('cancel.needs_settlement')}
                                test="impact-settlement"
                            >
                                <dl className="grid gap-1">
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-weak">
                                            {t('cancel.value_after')}
                                        </dt>
                                        <dd>
                                            <Money
                                                minor={
                                                    impact.settlement.money
                                                        .value_after_minor
                                                }
                                            />
                                        </dd>
                                    </div>
                                    <div
                                        className="flex justify-between gap-2"
                                        data-test="impact-refund"
                                    >
                                        <dt className="text-weak">
                                            {t('cancel.refundable')}
                                        </dt>
                                        <dd className="font-semibold">
                                            <Money
                                                minor={
                                                    impact.settlement.money
                                                        .refund_minor
                                                }
                                            />
                                        </dd>
                                    </div>
                                </dl>
                                {impact.settlement.agreement && (
                                    <ul className="grid gap-1 text-caption">
                                        {impact.settlement.agreement.dues.map(
                                            (d) => (
                                                <li
                                                    key={d.reference}
                                                    className="flex justify-between gap-2"
                                                >
                                                    <span>{d.name}</span>
                                                    <Money
                                                        minor={
                                                            d.amount_after_minor
                                                        }
                                                    />
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                                <p className="text-body">
                                    {t('cancel.release', {
                                        n: impact.settlement.stock.length,
                                    })}
                                </p>
                                {impact.settlement.stock.map((r) => (
                                    <p
                                        key={r.reservation_id}
                                        className="text-caption text-weak"
                                    >
                                        <bdi dir="ltr">{r.number}</bdi> ·{' '}
                                        {r.item} ·{' '}
                                        <bdi dir="ltr">
                                            {formatQty(r.release_milli)}
                                        </bdi>
                                    </p>
                                ))}
                                {impact.settlement.purchases.map((p) => (
                                    <p
                                        key={p.id}
                                        className="text-caption text-warning-text"
                                    >
                                        {t('cancel.purchase', {
                                            number: p.number,
                                            supplier: p.supplier,
                                        })}
                                    </p>
                                ))}
                                {impact.settlement.commission && (
                                    <p className="text-caption">
                                        {t('cancel.commission', {
                                            user: impact.settlement.commission
                                                .user,
                                        })}{' '}
                                        {impact.settlement.commission
                                            .difference_minor !== undefined && (
                                            <Money
                                                minor={
                                                    impact.settlement.commission
                                                        .difference_minor
                                                }
                                            />
                                        )}
                                    </p>
                                )}
                                <p className="text-caption text-weak">
                                    {t('cancel.no_auto_refund')}
                                </p>
                            </Section>
                        </div>
                        <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={confirm}
                                loading={busy}
                                disabled={busy}
                                data-test="cancel-confirm"
                            >
                                {t('cancel.confirm')}
                            </Button>
                        </div>
                    </div>
                )}

                {pending && can.review && (
                    <Card className="gap-3" data-test="reject-form">
                        <TextareaField
                            label={t('cancel.reject_reason')}
                            hint={t('cancel.reject_hint')}
                            value={rejectReason}
                            onChange={(e) => setRejectReason(e.target.value)}
                            required
                        />
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={
                                    busy || rejectReason.trim().length < 3
                                }
                                onClick={() =>
                                    write(
                                        CancellationController.reject.url(
                                            pending.id,
                                        ),
                                        {
                                            reason: rejectReason,
                                            lock_version: pending.lock_version,
                                        },
                                    )
                                }
                            >
                                {t('cancel.reject')}
                            </Button>
                        </div>
                    </Card>
                )}

                {settlement && (
                    <SettlementCard
                        settlement={settlement}
                        amounts={amounts_visible}
                    />
                )}

                {history.length > 0 && (
                    <Card className="gap-2" data-test="cancel-history">
                        <h2 className="text-heading text-strong">
                            {t('cancel.history')}
                        </h2>
                        <ul className="grid gap-2">
                            {history.map((r) => (
                                <li key={r.id}>
                                    <bdi dir="ltr">{r.number}</bdi>{' '}
                                    <Badge
                                        variant={
                                            r.status === 'accepted'
                                                ? 'success'
                                                : 'neutral'
                                        }
                                    >
                                        {t(
                                            `cancel.status.${r.status}` as TranslationKey,
                                        )}
                                    </Badge>{' '}
                                    <span className="text-caption text-weak">
                                        {t(
                                            `cancel.scope_${r.scope}` as TranslationKey,
                                        )}
                                        {r.decided_at
                                            ? ` · ${formatDate(r.decided_at, locale)}`
                                            : ''}
                                        {r.decided_by
                                            ? ` · ${r.decided_by}`
                                            : ''}
                                    </span>
                                    {r.decision_reason && (
                                        <p className="text-caption text-weak">
                                            {r.decision_reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </>
    );
}

/** The order's one settlement follow-up (INT-08): each remaining action with its source, until all are done. */
function SettlementCard({
    settlement,
    amounts,
}: {
    settlement: FollowUp;
    amounts: boolean;
}) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const [resolving, setResolving] = useState<number | null>(null);
    const [note, setNote] = useState('');
    const [status, setStatus] = useState<'done' | 'dropped'>('done');
    const [busy, setBusy] = useState(false);

    const resolve = async (item: SettlementItem) => {
        setBusy(true);
        const result = await api(
            'POST',
            CancellationController.resolve.url(item.id),
            {
                reason: note,
                status,
                lock_version: item.lock_version,
                idempotency_key: key,
            },
        );
        setBusy(false);
        renew();
        if (result.ok) {
            setResolving(null);
            setNote('');
            router.reload();
        } else {
            toast.error(result.message ?? t('cancel.failed'));
        }
    };

    return (
        <Card className="gap-3" data-test="settlement-followup">
            <h2 className="text-heading flex items-center gap-2 text-strong">
                <Icon icon={HandCoins} />
                {t('settlement.title')}
                <Badge
                    variant={
                        settlement.status === 'open' ? 'warning' : 'success'
                    }
                >
                    {t(
                        `settlement.status.${settlement.status}` as TranslationKey,
                    )}
                </Badge>
            </h2>
            <ul className="grid gap-3">
                {settlement.items.map((item) => (
                    <li
                        key={item.id}
                        className="grid gap-1 border-b border-stroke-weak pb-3 last:border-0"
                        data-test="settlement-item"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-semibold text-strong">
                                {t(
                                    `settlement.kind.${item.kind}` as TranslationKey,
                                )}
                                {typeof item.details.number === 'string' && (
                                    <>
                                        {' '}
                                        <bdi dir="ltr">
                                            {item.details.number}
                                        </bdi>
                                    </>
                                )}
                            </span>
                            <Badge
                                variant={
                                    item.status === 'open'
                                        ? 'warning'
                                        : item.status === 'done'
                                          ? 'success'
                                          : 'neutral'
                                }
                            >
                                {t(
                                    `settlement.item.${item.status}` as TranslationKey,
                                )}
                            </Badge>
                        </div>
                        {amounts && item.target_minor != null && (
                            <p className="text-caption">
                                {t('settlement.target')}{' '}
                                <Money minor={item.target_minor} />
                                {item.covered_minor != null && (
                                    <>
                                        {' · '}
                                        {t('settlement.covered')}{' '}
                                        <Money minor={item.covered_minor} />
                                    </>
                                )}
                            </p>
                        )}
                        {item.kind === 'refund' && item.status === 'open' && (
                            <p className="text-caption text-weak">
                                {t('settlement.refund_hint')}
                            </p>
                        )}
                        {item.note && (
                            <p className="text-caption text-weak">
                                {item.note}
                                {item.resolver ? ` — ${item.resolver}` : ''}
                            </p>
                        )}
                        {item.can_resolve && resolving !== item.id && (
                            <div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setResolving(item.id)}
                                >
                                    {t('settlement.resolve')}
                                </Button>
                            </div>
                        )}
                        {resolving === item.id && (
                            <div className="grid gap-2">
                                <RadioGroup
                                    legend={t('settlement.resolve')}
                                    name={`resolve-${item.id}`}
                                    inline
                                    value={status}
                                    onChange={(v) =>
                                        setStatus(v as 'done' | 'dropped')
                                    }
                                    options={[
                                        {
                                            value: 'done',
                                            label: t('settlement.item.done'),
                                        },
                                        {
                                            value: 'dropped',
                                            label: t('settlement.item.dropped'),
                                        },
                                    ]}
                                />
                                <TextareaField
                                    label={t('settlement.note')}
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    required
                                />
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={
                                            busy || note.trim().length < 3
                                        }
                                        onClick={() => resolve(item)}
                                    >
                                        {t('settlement.save')}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setResolving(null)}
                                    >
                                        {t('settlement.cancel')}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}

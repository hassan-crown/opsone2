import { Head, Link } from '@inertiajs/react';
import { ArrowLeftRight, CalendarClock, Plus, Receipt } from 'lucide-react';
import { useState } from 'react';
import OrderFinanceController from '@/actions/App/Http/Controllers/Finance/OrderFinanceController';
import PaymentController from '@/actions/App/Http/Controllers/Finance/PaymentController';
import ProofController from '@/actions/App/Http/Controllers/Finance/ProofController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { toMajor } from '@/components/agreement/money';
import {
    FinanceReasonDialog,
    ReallocateDialog,
    RecordPaymentDialog,
    ReviewProofDialog,
    ScheduleDialog,
    TransferDialog,
} from '@/components/finance/dialogs';
import {
    DueStatusBadge,
    PaymentStatusBadge,
    ProofStatusBadge,
} from '@/components/finance/status';
import type { FinanceDue, FinancePayload } from '@/components/finance/types';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { KindBadge, StatusBadge } from '@/components/orders/status';
import type { OrderRow } from '@/components/orders/types';
import { SettlementCard } from '@/components/settlement/settlement-card';
import type { SettlementPayload } from '@/components/settlement/types';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    order: OrderRow;
    finance: FinancePayload;
    settlement: SettlementPayload;
    today: string;
};

type Open =
    | { type: 'record' }
    | { type: 'schedule' }
    | { type: 'transfer' }
    | { type: 'review'; id: number }
    | { type: 'correction'; id: number }
    | { type: 'cancel_proof'; id: number }
    | { type: 'reallocate'; id: number }
    | null;

/** A money figure, or «hidden» when the viewer has no P-37 (never 0 for an unknown amount). */
function Money({
    minor,
    strong,
}: {
    minor: number | null | undefined;
    strong?: boolean;
}) {
    const t = useT();
    if (minor === null || minor === undefined) {
        return <span className="text-weak">{t('finance.hidden')}</span>;
    }

    return <Amount value={toMajor(minor)} strong={strong} />;
}

/**
 * FinOrder (+ FinOrderPaid, FinSales, FinOrderMobile, FinEnglish, FinDark): the order's money — agreed value, net
 * collected, remaining and due now (FIN-01), the schedule with derived statuses, receipts, customer proofs waiting
 * for a decision and the stage collection checks (INT-07). The server removes amounts without P-37 and decides every
 * action again; the page only shows what `can` allows.
 */
export default function OrderFinance({
    order,
    finance,
    settlement,
    today,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [payload, setPayload] = useState(finance);
    const [fromProps, setFromProps] = useState(finance);
    const [settled, setSettled] = useState(settlement);
    if (fromProps !== finance) {
        setFromProps(finance);
        setPayload(finance);
        setSettled(settlement);
    }
    const [open, setOpen] = useState<Open>(null);
    const {
        summary,
        dues,
        payments,
        proofs,
        conditions,
        can,
        amounts_visible: amounts,
    } = payload;

    const done = (next: FinancePayload, message?: string) => {
        setPayload(next);
        setOpen(null);
        if (message) {
            notify('success', { title: message });
        }
    };
    const firstOpenDue = dues.find((d) => d.status !== 'paid');
    const pendingProofs = proofs.filter(
        (p) => p.status === 'under_review' && !p.replaced,
    );
    const proof = (id: number) => proofs.find((p) => p.id === id);
    const payment = (id: number) => payments.find((p) => p.id === id);
    const dueName = (due: FinanceDue) => due.name ?? due.reference;

    return (
        <>
            <Head title={`${t('finance.order.title')} · ${order.number}`} />
            <PageHeader
                title={t('finance.order.title')}
                description={`${order.number} · ${order.customer.name}`}
                actions={
                    <>
                        <Button asChild variant="secondary">
                            <Link href={OrderController.show.url(order.id)}>
                                {t('finance.back_to_order')}
                            </Link>
                        </Button>
                        {can.record && (
                            <Button
                                type="button"
                                onClick={() => setOpen({ type: 'record' })}
                                data-test="record-payment"
                            >
                                <Icon icon={Plus} />
                                {t('finance.record.action')}
                            </Button>
                        )}
                    </>
                }
            />

            <div
                className="mb-4 flex flex-wrap items-center gap-2"
                data-test="finance-header"
            >
                <KindBadge kind={order.kind} />
                <StatusBadge status={order.status} />
                <span className="text-weak">
                    {t('orders.field.branch')}:{' '}
                    <span className="font-semibold text-strong">
                        {order.branch.name}
                    </span>
                </span>
                {!amounts && (
                    <Badge variant="neutral" data-test="amounts-hidden">
                        {t('finance.amounts_hidden')}
                    </Badge>
                )}
            </div>

            {payload.shortfalls.length > 0 && (
                <Alert
                    variant="warning"
                    className="mb-4"
                    data-test="shortfalls"
                >
                    <AlertBody>
                        <AlertTitle>{t('finance.shortfall.title')}</AlertTitle>
                        <p>
                            {t('finance.shortfall.body', {
                                checks: payload.shortfalls.join('، '),
                            })}
                        </p>
                    </AlertBody>
                </Alert>
            )}
            {summary && summary.excess_minor > 0 && (
                <Alert
                    variant="warning"
                    className="mb-4"
                    data-test="excess-alert"
                >
                    <AlertBody>
                        <AlertTitle>
                            {t('finance.excess.title')}{' '}
                            <Amount value={toMajor(summary.excess_minor)} />
                        </AlertTitle>
                        <p>
                            {payload.has_agreement
                                ? t('finance.excess.body')
                                : t('finance.excess.deposit')}
                        </p>
                        {can.allocate &&
                            payload.customer_orders.length > 0 &&
                            summary.credit_minor > 0 && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() =>
                                        setOpen({ type: 'transfer' })
                                    }
                                    data-test="open-transfer"
                                >
                                    <Icon icon={ArrowLeftRight} />
                                    {t('finance.transfer.action')}
                                </Button>
                            )}
                    </AlertBody>
                </Alert>
            )}

            <div
                className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                data-test="finance-summary"
            >
                {[
                    {
                        label: 'finance.summary.agreed',
                        minor: summary?.agreed_minor,
                        hint: payload.has_agreement
                            ? t('finance.summary.agreed_hint')
                            : t('finance.summary.no_agreement'),
                    },
                    {
                        label: 'finance.summary.collected',
                        minor: summary?.collected_minor,
                        hint:
                            pendingProofs.length > 0
                                ? t('finance.summary.proofs_pending', {
                                      count: pendingProofs.length,
                                  })
                                : t('finance.summary.collected_hint'),
                    },
                    {
                        label: 'finance.summary.remaining',
                        minor: summary?.remaining_minor,
                        hint: t('finance.summary.remaining_hint'),
                    },
                    {
                        label: 'finance.summary.due_now',
                        minor: summary?.due_now_minor,
                        hint: firstOpenDue
                            ? dueName(firstOpenDue)
                            : t('finance.summary.nothing_due'),
                    },
                ].map((stat) => (
                    <div
                        key={stat.label}
                        className="flex min-w-0 flex-col gap-1 rounded-lg border border-stroke-weak bg-surface p-5"
                    >
                        <span className="text-label text-weak">
                            {t(stat.label as TranslationKey)}
                        </span>
                        <p className="m-0 text-title text-strong tabular-nums">
                            {amounts ? (
                                <Money minor={stat.minor ?? null} />
                            ) : (
                                t('finance.hidden')
                            )}
                        </p>
                        <p className="m-0 text-caption text-weak">
                            {stat.hint}
                        </p>
                    </div>
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid min-w-0 content-start gap-6 lg:col-span-2">
                    {pendingProofs.length > 0 && (
                        <Card data-test="proofs">
                            <CardHeader>
                                <CardTitle>
                                    {t('finance.proofs.title')}
                                </CardTitle>
                                <CardDescription>
                                    {t('finance.proofs.description')}
                                </CardDescription>
                            </CardHeader>
                            <ul className="grid gap-3">
                                {pendingProofs.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-stroke-weak p-3"
                                        data-test="proof-row"
                                    >
                                        <div className="grid gap-1">
                                            <span className="font-semibold text-strong">
                                                {t('finance.proofs.item')}{' '}
                                                {amounts && (
                                                    <Money
                                                        minor={p.claimed_minor}
                                                    />
                                                )}
                                            </span>
                                            <span className="text-caption text-weak">
                                                {formatDateTime(
                                                    p.submitted_at,
                                                    locale,
                                                )}
                                                {p.reference && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        <bdi dir="ltr">
                                                            {p.reference}
                                                        </bdi>
                                                    </>
                                                )}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ProofStatusBadge
                                                status={p.status}
                                            />
                                            {can.review && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        setOpen({
                                                            type: 'review',
                                                            id: p.id,
                                                        })
                                                    }
                                                    data-test="review-proof"
                                                >
                                                    {t('finance.review.action')}
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card data-test="schedule">
                        <CardHeader>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <CardTitle>
                                    {t('finance.schedule.heading')}
                                </CardTitle>
                                {can.schedule && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setOpen({ type: 'schedule' })
                                        }
                                        data-test="edit-schedule"
                                    >
                                        <Icon icon={CalendarClock} />
                                        {t('finance.schedule.edit')}
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        {dues.length === 0 ? (
                            <EmptyState
                                icon={CalendarClock}
                                title={t('finance.schedule.empty')}
                                description={
                                    payload.has_agreement
                                        ? t('finance.schedule.empty_agreed')
                                        : t('finance.schedule.empty_body')
                                }
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-start text-body">
                                    <thead className="text-label text-weak">
                                        <tr>
                                            <th className="py-2 text-start">
                                                {t('finance.schedule.due')}
                                            </th>
                                            <th className="py-2 text-start">
                                                {t('finance.schedule.when')}
                                            </th>
                                            <th className="py-2 text-end">
                                                {t('finance.field.amount')}
                                            </th>
                                            <th className="py-2 text-end">
                                                {t(
                                                    'finance.schedule.allocated',
                                                )}
                                            </th>
                                            <th className="py-2 text-end">
                                                {t('finance.schedule.left')}
                                            </th>
                                            <th className="py-2 text-start">
                                                {t('finance.field.status')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {dues.map((due) => (
                                            <tr
                                                key={due.id}
                                                className="border-t border-stroke-weak"
                                                data-test="due-row"
                                            >
                                                <td className="py-2">
                                                    <span className="font-semibold text-strong">
                                                        {dueName(due)}
                                                    </span>
                                                    <span className="block text-caption text-weak">
                                                        {t(
                                                            `finance.basis.${due.basis}` as TranslationKey,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="py-2">
                                                    {t(
                                                        `finance.event.${due.event}` as TranslationKey,
                                                    )}
                                                    {due.due_on && (
                                                        <span className="block text-caption text-weak">
                                                            {formatDate(
                                                                due.due_on,
                                                                locale,
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-2 text-end">
                                                    <Money
                                                        minor={due.amount_minor}
                                                    />
                                                </td>
                                                <td className="py-2 text-end">
                                                    <Money
                                                        minor={
                                                            due.allocated_minor
                                                        }
                                                    />
                                                </td>
                                                <td className="py-2 text-end">
                                                    <Money
                                                        minor={
                                                            due.remaining_minor
                                                        }
                                                        strong
                                                    />
                                                </td>
                                                <td className="py-2">
                                                    <DueStatusBadge
                                                        status={due.status}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {summary &&
                                    summary.unscheduled_minor !== null && (
                                        <p
                                            className="mt-2 text-caption text-weak"
                                            data-test="schedule-note"
                                        >
                                            {summary.unscheduled_minor > 0 ? (
                                                <>
                                                    {t(
                                                        'finance.schedule.unscheduled',
                                                    )}{' '}
                                                    <Amount
                                                        value={toMajor(
                                                            summary.unscheduled_minor,
                                                        )}
                                                    />
                                                </>
                                            ) : (
                                                t('finance.schedule.complete')
                                            )}
                                        </p>
                                    )}
                            </div>
                        )}
                    </Card>

                    <Card data-test="payments">
                        <CardHeader>
                            <CardTitle>{t('finance.payments.title')}</CardTitle>
                            <CardDescription>
                                {t('finance.payments.description')}
                            </CardDescription>
                        </CardHeader>
                        {payments.length === 0 ? (
                            <EmptyState
                                icon={Receipt}
                                title={t('finance.payments.empty')}
                            />
                        ) : (
                            <ul className="grid gap-3">
                                {payments.map((p) => (
                                    <li
                                        key={p.id}
                                        className="grid gap-2 rounded-md border border-stroke-weak p-3"
                                        data-test="payment-row"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-semibold text-strong">
                                                <bdi dir="ltr">
                                                    {p.receipt_number ?? '—'}
                                                </bdi>
                                            </span>
                                            <div className="flex items-center gap-2">
                                                {amounts && (
                                                    <Money
                                                        minor={
                                                            p.share_minor ??
                                                            p.amount_minor
                                                        }
                                                        strong
                                                    />
                                                )}
                                                <PaymentStatusBadge
                                                    status={p.status}
                                                />
                                            </div>
                                        </div>
                                        <span className="text-caption text-weak">
                                            {t(
                                                `finance.method.${p.method}` as TranslationKey,
                                            )}{' '}
                                            · {formatDate(p.paid_on, locale)}
                                            {p.reference && (
                                                <>
                                                    {' '}
                                                    ·{' '}
                                                    <bdi dir="ltr">
                                                        {p.reference}
                                                    </bdi>
                                                </>
                                            )}
                                            {' · '}
                                            {t(
                                                `finance.origin.${p.origin}` as TranslationKey,
                                            )}
                                        </span>
                                        {amounts &&
                                            p.allocations &&
                                            p.allocations.length > 0 && (
                                                <span className="text-caption text-weak">
                                                    {p.allocations
                                                        .map((a) => {
                                                            const due =
                                                                dues.find(
                                                                    (d) =>
                                                                        d.id ===
                                                                        a.due_id,
                                                                );

                                                            return due
                                                                ? `${dueName(due)}: ${(a.amount_minor / 100).toFixed(2)}`
                                                                : null;
                                                        })
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            )}
                                        <div className="flex flex-wrap gap-2">
                                            {p.evidence.map(
                                                (file) =>
                                                    file.url && (
                                                        <a
                                                            key={file.uuid}
                                                            href={file.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-caption text-link underline"
                                                        >
                                                            {file.name}
                                                        </a>
                                                    ),
                                            )}
                                            {can.allocate &&
                                                dues.length > 0 &&
                                                p.status === 'confirmed' &&
                                                (p.share_minor ?? 0) > 0 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setOpen({
                                                                type: 'reallocate',
                                                                id: p.id,
                                                            })
                                                        }
                                                        data-test="reallocate"
                                                    >
                                                        {t(
                                                            'finance.reallocate.action',
                                                        )}
                                                    </Button>
                                                )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="grid min-w-0 content-start gap-6">
                    <SettlementCard
                        orderId={order.id}
                        orderNumber={order.number}
                        lockVersion={payload.lock_version}
                        settlement={settled}
                        dues={dues}
                        today={today}
                        onChange={(next) => {
                            setPayload(next.finance);
                            setSettled(next.settlement);
                        }}
                    />
                    <Card data-test="conditions">
                        <CardHeader>
                            <CardTitle>
                                {t('finance.conditions.title')}
                            </CardTitle>
                            <CardDescription>
                                {t('finance.conditions.description')}
                            </CardDescription>
                        </CardHeader>
                        {conditions.length === 0 ? (
                            <p className="text-weak">
                                {t('finance.conditions.none')}
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {conditions.map((c) => (
                                    <li
                                        key={c.reference}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <span>
                                            {c.name ?? c.reference}
                                            {amounts &&
                                                c.threshold_minor !==
                                                    undefined && (
                                                    <span className="block text-caption text-weak">
                                                        {c.threshold_minor ===
                                                        null ? (
                                                            t(
                                                                'finance.conditions.unknown',
                                                            )
                                                        ) : (
                                                            <Amount
                                                                value={toMajor(
                                                                    c.threshold_minor,
                                                                )}
                                                            />
                                                        )}
                                                    </span>
                                                )}
                                        </span>
                                        <Badge
                                            variant={
                                                c.met ? 'success' : 'warning'
                                            }
                                        >
                                            {c.met
                                                ? t('finance.conditions.met')
                                                : t(
                                                      'finance.conditions.not_met',
                                                  )}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    {proofs.some(
                        (p) => p.status !== 'under_review' || p.replaced,
                    ) && (
                        <Card data-test="proof-history">
                            <CardHeader>
                                <CardTitle>
                                    {t('finance.proofs.history')}
                                </CardTitle>
                            </CardHeader>
                            <ul className="grid gap-2">
                                {proofs
                                    .filter(
                                        (p) =>
                                            p.status !== 'under_review' ||
                                            p.replaced,
                                    )
                                    .map((p) => (
                                        <li key={p.id} className="grid gap-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span>
                                                    {formatDate(
                                                        p.submitted_at,
                                                        locale,
                                                    )}
                                                </span>
                                                <ProofStatusBadge
                                                    status={p.status}
                                                />
                                            </div>
                                            {p.receipt_number && (
                                                <span className="text-caption text-weak">
                                                    {t(
                                                        'finance.proofs.linked',
                                                        {
                                                            receipt:
                                                                p.receipt_number,
                                                        },
                                                    )}
                                                </span>
                                            )}
                                            {p.correction_reason && (
                                                <span className="text-caption text-weak">
                                                    {p.correction_reason}
                                                </span>
                                            )}
                                            {can.review &&
                                                p.status ===
                                                    'needs_correction' &&
                                                !p.replaced && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setOpen({
                                                                type: 'cancel_proof',
                                                                id: p.id,
                                                            })
                                                        }
                                                    >
                                                        {t(
                                                            'finance.proofs.cancel',
                                                        )}
                                                    </Button>
                                                )}
                                        </li>
                                    ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>

            {open?.type === 'record' && (
                <RecordPaymentDialog
                    url={OrderFinanceController.recordPayment.url(order.id)}
                    orderNumber={order.number}
                    lockVersion={payload.lock_version}
                    defaultMinor={summary?.due_now_minor ?? 0}
                    dueName={firstOpenDue ? dueName(firstOpenDue) : null}
                    customerOrders={payload.customer_orders}
                    today={today}
                    onClose={() => setOpen(null)}
                    onDone={(next, receipt) =>
                        done(
                            next,
                            t('finance.record.done', {
                                receipt: receipt ?? '',
                            }),
                        )
                    }
                />
            )}
            {open?.type === 'schedule' && (
                <ScheduleDialog
                    url={OrderFinanceController.updateSchedule.url(order.id)}
                    lockVersion={payload.lock_version}
                    dues={dues}
                    agreedMinor={summary?.agreed_minor ?? null}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('finance.schedule.done'))}
                />
            )}
            {open?.type === 'transfer' && summary && (
                <TransferDialog
                    url={OrderFinanceController.transferCredit.url(order.id)}
                    orderNumber={order.number}
                    lockVersion={payload.lock_version}
                    creditMinor={summary.credit_minor}
                    customerOrders={payload.customer_orders}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('finance.transfer.done'))}
                />
            )}
            {open?.type === 'review' && proof(open.id) && (
                <ReviewProofDialog
                    proof={proof(open.id)!}
                    url={ProofController.confirm.url(open.id)}
                    onClose={() => setOpen(null)}
                    onCorrection={() =>
                        setOpen({ type: 'correction', id: open.id })
                    }
                    onDone={(next) => done(next, t('finance.review.done'))}
                />
            )}
            {open?.type === 'correction' && proof(open.id) && (
                <FinanceReasonDialog
                    title={t('finance.correction.title')}
                    description={t('finance.correction.description')}
                    confirm={t('finance.correction.confirm')}
                    url={ProofController.requestCorrection.url(open.id)}
                    lockVersion={proof(open.id)!.lock_version}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('finance.correction.done'))}
                />
            )}
            {open?.type === 'cancel_proof' && proof(open.id) && (
                <FinanceReasonDialog
                    title={t('finance.proofs.cancel_title')}
                    description={t('finance.proofs.cancel_description')}
                    confirm={t('finance.proofs.cancel')}
                    url={ProofController.cancel.url(open.id)}
                    lockVersion={proof(open.id)!.lock_version}
                    destructive
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next)}
                />
            )}
            {open?.type === 'reallocate' && payment(open.id) && (
                <ReallocateDialog
                    url={PaymentController.reallocate.url(open.id)}
                    orderId={order.id}
                    lockVersion={payload.lock_version}
                    payment={payment(open.id)!}
                    dues={dues}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('finance.reallocate.done'))}
                />
            )}
        </>
    );
}

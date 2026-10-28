import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, UserRoundPen } from 'lucide-react';
import { useState } from 'react';
import OrderSaleController from '@/actions/App/Http/Controllers/Commissions/OrderSaleController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { bpToPercent, percentToBp } from '@/components/agreement/money';
import {
    entryKey,
    Minor,
    Rate,
    CorrectionMove,
} from '@/components/commissions/parts';
import type { Correction, SalePayload } from '@/components/commissions/types';
import { Shell } from '@/components/finance/dialogs';
import { useFinanceCall } from '@/components/finance/use-finance-call';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    order: { id: number; number: string; customer: { name: string } };
    sale: SalePayload;
};

type Open =
    | { type: 'owner' | 'rate' }
    | { type: 'reject'; correction: Correction }
    | null;
type Response = { sale: SalePayload };

const STATUS_TONE: Record<
    Correction['status'],
    'warning' | 'success' | 'neutral' | 'danger'
> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

/**
 * «صاحب المبيعة وتصحيح العمولة» (SAL-11, SAL-16, Q-37 a): who owns the sale and at which pinned rate, how its
 * commission accrued, and the owner / rate corrections — a financial decision with a reason, approved by someone
 * else, that moves the accrual without rewriting history.
 */
export default function OrderSale({ order, sale: initial }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [payload, setPayload] = useState(initial);
    const [open, setOpen] = useState<Open>(null);
    const deciding = useFinanceCall<Response>();
    const sale = payload.sale;

    const done = (next: SalePayload, message: string) => {
        setPayload(next);
        setOpen(null);
        notify('success', { title: message });
    };
    const decide = async (c: Correction, step: 'approve' | 'cancel') => {
        const url =
            step === 'approve'
                ? OrderSaleController.approve.url(c.id)
                : OrderSaleController.cancel.url(c.id);
        const data = await deciding.run('POST', url, {
            lock_version: c.lock_version,
        });
        if (data) {
            done(
                data.sale,
                t(`commissions.corrections.${step}_done` as TranslationKey),
            );
        }
    };

    return (
        <>
            <Head title={t('commissions.sale.title')} />
            <PageHeader
                back={
                    <Link
                        href={OrderController.show.url(order.id)}
                        className="inline-flex items-center gap-1 text-caption text-weak hover:underline"
                    >
                        <Icon icon={ArrowLeft} className="rtl:rotate-180" />
                        <bdi dir="ltr">{order.number}</bdi>
                    </Link>
                }
                title={t('commissions.sale.title')}
                description={t('commissions.sale.description')}
                actions={
                    <>
                        {payload.can.request_owner && (
                            <Button
                                type="button"
                                onClick={() => setOpen({ type: 'owner' })}
                                data-test="request-owner"
                            >
                                <Icon icon={UserRoundPen} />
                                {t('commissions.corrections.request_owner')}
                            </Button>
                        )}
                        {payload.can.request_rate && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setOpen({ type: 'rate' })}
                                data-test="request-rate"
                            >
                                {t('commissions.corrections.request_rate')}
                            </Button>
                        )}
                    </>
                }
            />
            {deciding.error && (
                <p className="mb-4 text-danger-text">{deciding.error}</p>
            )}

            {!sale ? (
                <Card>
                    <EmptyState
                        icon={UserRoundPen}
                        title={t('commissions.sale.none')}
                        description={t('commissions.sale.none_body')}
                    />
                </Card>
            ) : (
                <Card className="mb-6" data-test="sale-card">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-caption text-weak">
                                {t('commissions.sale.owner')}
                            </dt>
                            <dd className="font-semibold">
                                {sale.member.name}
                            </dd>
                            <dd className="text-caption text-weak">
                                {t(
                                    `commissions.owner_source.${sale.owner_source}` as TranslationKey,
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-caption text-weak">
                                {t('commissions.col.rate')}
                            </dt>
                            <dd className="font-semibold">
                                <Rate bp={sale.rate_bp} />
                            </dd>
                            <dd className="text-caption text-weak">
                                {t(
                                    `commissions.rate_source.${sale.rate_source}` as TranslationKey,
                                )}{' '}
                                · {formatDate(sale.pinned_at, locale)}
                            </dd>
                        </div>
                        {payload.amounts_visible && (
                            <>
                                <div>
                                    <dt className="text-caption text-weak">
                                        {t('commissions.col.eligible')}
                                    </dt>
                                    <dd>
                                        <Minor value={sale.eligible_minor} />
                                    </dd>
                                    <dd className="text-caption text-weak">
                                        {t('commissions.col.collected')}:{' '}
                                        <Minor value={sale.collected_minor} /> /{' '}
                                        <Minor value={sale.agreed_minor} />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-weak">
                                        {t('commissions.col.commission')}
                                    </dt>
                                    <dd>
                                        <Minor
                                            value={sale.accrued_minor}
                                            strong
                                        />
                                    </dd>
                                </div>
                            </>
                        )}
                    </dl>
                    <p className="mt-3 text-caption text-weak">
                        {t('commissions.basis_hint')}
                    </p>
                </Card>
            )}

            {payload.corrections.length > 0 && (
                <Card className="mb-6">
                    <h2 className="text-heading mb-3 text-strong">
                        {t('commissions.corrections.title')}
                    </h2>
                    <ul className="grid gap-3" data-test="corrections">
                        {payload.corrections.map((c) => (
                            <li
                                key={c.id}
                                className="grid gap-2 rounded-md border border-stroke-weak p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={STATUS_TONE[c.status]}>
                                        {t(
                                            `commissions.corrections.status.${c.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                    <span className="font-semibold">
                                        {t(
                                            `commissions.corrections.kind.${c.kind}` as TranslationKey,
                                        )}
                                    </span>
                                    <span className="text-weak">
                                        <CorrectionMove correction={c} />
                                    </span>
                                </div>
                                <p className="text-body">{c.reason}</p>
                                <p className="text-caption text-weak">
                                    {t('commissions.corrections.requested_by', {
                                        name: c.requested_by ?? '',
                                    })}{' '}
                                    · {formatDate(c.created_at, locale)}
                                    {c.decided_by &&
                                        ` · ${t('commissions.corrections.decided_by', { name: c.decided_by })}`}
                                    {c.decision_reason &&
                                        ` · ${c.decision_reason}`}
                                </p>
                                {(c.can.decide || c.can.cancel) && (
                                    <div className="flex flex-wrap gap-2">
                                        {c.can.decide && (
                                            <>
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        decide(c, 'approve')
                                                    }
                                                    loading={deciding.busy}
                                                    data-test="approve-correction"
                                                >
                                                    {t(
                                                        'commissions.corrections.approve',
                                                    )}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        setOpen({
                                                            type: 'reject',
                                                            correction: c,
                                                        })
                                                    }
                                                >
                                                    {t(
                                                        'commissions.corrections.reject',
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                        {c.can.cancel && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    decide(c, 'cancel')
                                                }
                                                loading={deciding.busy}
                                            >
                                                {t(
                                                    'commissions.corrections.withdraw',
                                                )}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {payload.amounts_visible && payload.history.length > 0 && (
                <Card>
                    <h2 className="text-heading mb-3 text-strong">
                        {t('commissions.entries')}
                    </h2>
                    <ul className="grid gap-2" data-test="sale-entries">
                        {payload.history.flatMap((h) =>
                            h.entries.map((e) => (
                                <li
                                    key={e.id}
                                    className="flex items-start justify-between gap-3 border-b border-stroke-weak pb-2 last:border-0"
                                >
                                    <span className="min-w-0">
                                        <span className="block font-semibold">
                                            {t(entryKey(e))}
                                        </span>
                                        <span className="text-caption text-weak">
                                            {h.member} · <Rate bp={h.rate_bp} />{' '}
                                            · {formatDate(e.created_at, locale)}
                                            {e.reason && ` · ${e.reason}`}
                                        </span>
                                    </span>
                                    <Minor value={e.amount_minor} />
                                </li>
                            )),
                        )}
                    </ul>
                </Card>
            )}

            {(open?.type === 'owner' || open?.type === 'rate') && sale && (
                <RequestDialog
                    kind={open.type}
                    orderId={order.id}
                    payload={payload}
                    onClose={() => setOpen(null)}
                    onDone={(next) =>
                        done(next, t('commissions.corrections.requested'))
                    }
                />
            )}
            {open?.type === 'reject' && (
                <RejectDialog
                    correction={open.correction}
                    onClose={() => setOpen(null)}
                    onDone={(next) =>
                        done(next, t('commissions.corrections.reject_done'))
                    }
                />
            )}
        </>
    );
}

function RequestDialog({
    kind,
    orderId,
    payload,
    onClose,
    onDone,
}: {
    kind: 'owner' | 'rate';
    orderId: number;
    payload: SalePayload;
    onClose: () => void;
    onDone: (next: SalePayload) => void;
}) {
    const t = useT();
    const call = useFinanceCall<Response>();
    const sale = payload.sale!;
    const [to, setTo] = useState('');
    const [rate, setRate] = useState(bpToPercent(sale.rate_bp));
    const [reason, setReason] = useState('');

    const submit = async () => {
        const body =
            kind === 'owner'
                ? { kind, to_user_id: to === '' ? null : Number(to), reason }
                : { kind, rate_bp: percentToBp(rate) ?? -1, reason };
        const data = await call.run(
            'POST',
            OrderSaleController.requestCorrection.url(orderId),
            body,
        );
        if (data) {
            onDone(data.sale);
        }
    };

    return (
        <Shell
            test="request-correction"
            title={t(
                `commissions.corrections.request_${kind}` as TranslationKey,
            )}
            description={t('commissions.corrections.request_hint')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="submit-correction"
                >
                    {t('commissions.corrections.submit')}
                </Button>
            }
        >
            {kind === 'owner' ? (
                <SelectField
                    label={t('commissions.corrections.new_owner')}
                    value={to}
                    required
                    placeholder={t('commissions.corrections.choose')}
                    options={payload.members
                        .filter((m) => m.id !== sale.member.id)
                        .map((m) => ({ value: String(m.id), label: m.name }))}
                    error={call.fieldError('to_user_id')}
                    onChange={(e) => setTo(e.target.value)}
                />
            ) : (
                <TextField
                    label={t('commissions.corrections.new_rate')}
                    hint={t('commissions.corrections.current_rate', {
                        rate: `${bpToPercent(sale.rate_bp)}%`,
                    })}
                    inputMode="decimal"
                    dir="ltr"
                    required
                    value={rate}
                    error={call.fieldError('rate_bp')}
                    onChange={(e) => setRate(e.target.value)}
                />
            )}
            <TextareaField
                label={t('commissions.reason')}
                required
                value={reason}
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
        </Shell>
    );
}

function RejectDialog({
    correction,
    onClose,
    onDone,
}: {
    correction: Correction;
    onClose: () => void;
    onDone: (next: SalePayload) => void;
}) {
    const t = useT();
    const call = useFinanceCall<Response>();
    const [reason, setReason] = useState('');

    const submit = async () => {
        const data = await call.run(
            'POST',
            OrderSaleController.reject.url(correction.id),
            { reason, lock_version: correction.lock_version },
        );
        if (data) {
            onDone(data.sale);
        }
    };

    return (
        <Shell
            test="reject-correction"
            title={t('commissions.corrections.reject')}
            description={correction.reason}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    variant="destructive"
                    onClick={submit}
                    loading={call.busy}
                >
                    {t('commissions.corrections.reject')}
                </Button>
            }
        >
            <TextareaField
                label={t('commissions.reason')}
                required
                value={reason}
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
        </Shell>
    );
}

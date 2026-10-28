import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Banknote } from 'lucide-react';
import { useState } from 'react';
import CommissionController from '@/actions/App/Http/Controllers/Commissions/CommissionController';
import OrderSaleController from '@/actions/App/Http/Controllers/Commissions/OrderSaleController';
import PayoutController from '@/actions/App/Http/Controllers/Commissions/PayoutController';
import { bpToPercent, newKey } from '@/components/agreement/money';
import {
    CancelDialog,
    CorrectDialog,
    PayoutDialog,
    RecordDialog,
} from '@/components/commissions/dialogs';
import {
    entryKey,
    Minor,
    PayoutBadge,
    Rate,
} from '@/components/commissions/parts';
import type { Payout, Statement } from '@/components/commissions/types';
import { useFinanceCall } from '@/components/finance/use-finance-call';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { StatCard } from '@/components/opsone/stat-card';
import { notify } from '@/components/opsone/toast';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatAmount, formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Open =
    | { type: 'pay' }
    | { type: 'record' | 'cancel' | 'correct'; id: number }
    | null;

/**
 * A member's commission statement (FinMyCommission, SAL-15) and FinPayout: sales with their pinned rate, eligible
 * base, collected and commission; the recent differences; payouts with their M-33 actions.
 */
export default function CommissionMember(initial: Statement) {
    const t = useT();
    const { locale } = useLocale();
    const [s, setS] = useState(initial);
    const [open, setOpen] = useState<Open>(null);
    const approving = useFinanceCall<{ statement: Statement }>();
    const payout = (id: number): Payout | undefined =>
        s.payouts.find((p) => p.id === id);
    const draft = s.payouts.find((p) => p.status === 'draft') ?? null;
    const reserved = s.payouts.some((p) => p.status === 'reserved');
    const money = (minor: number) => formatAmount(minor / 100, locale);

    const done = (next: Statement, message: string) => {
        setS(next);
        setOpen(null);
        notify('success', { title: message });
    };
    const reserve = async (p: Payout) => {
        const data = await approving.run(
            'POST',
            PayoutController.approve.url(p.id),
            { idempotency_key: newKey(), lock_version: p.lock_version },
        );
        if (data) {
            done(data.statement, t('commissions.payout.reserved_done'));
        }
    };

    return (
        <>
            <Head
                title={t('commissions.statement_of', { name: s.member.name })}
            />
            <PageHeader
                back={
                    <Link
                        href={CommissionController.index.url()}
                        className="inline-flex items-center gap-1 text-caption text-weak hover:underline"
                    >
                        <Icon icon={ArrowLeft} className="rtl:rotate-180" />
                        {t('commissions.title')}
                    </Link>
                }
                title={t('commissions.statement_of', { name: s.member.name })}
                description={t('commissions.statement_hint', {
                    rate: `${bpToPercent(s.member.rate.rate_bp)}%`,
                })}
                actions={
                    s.can.pay &&
                    !reserved && (
                        <Button
                            type="button"
                            onClick={() => setOpen({ type: 'pay' })}
                            disabled={(s.payable?.amount_minor ?? 0) <= 0}
                            data-test="open-payout"
                        >
                            <Icon icon={Banknote} />
                            {t('commissions.pay')}
                        </Button>
                    )
                }
            />
            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard
                    label={t('commissions.col.accrued')}
                    value={money(s.summary.accrued_minor)}
                />
                <StatCard
                    label={t('commissions.col.held')}
                    value={money(s.summary.held_minor)}
                    hint={t('commissions.held_short')}
                />
                <StatCard
                    label={t('commissions.col.paid')}
                    value={money(
                        s.summary.paid_minor + s.summary.reserved_minor,
                    )}
                />
                <StatCard
                    label={t('commissions.col.available')}
                    value={money(s.summary.available_minor)}
                    hint={
                        s.summary.available_minor < 0
                            ? t('commissions.carried')
                            : undefined
                    }
                />
            </div>
            {approving.error && (
                <p className="mb-4 text-danger-text">{approving.error}</p>
            )}

            <Card className="mb-6">
                <h2 className="text-heading mb-3 text-strong">
                    {t('commissions.sales')}
                </h2>
                <TableWrap>
                    <Table
                        caption={t('commissions.sales')}
                        data-test="member-sales"
                    >
                        <thead>
                            <tr>
                                <Th>{t('commissions.col.order')}</Th>
                                <Th>{t('commissions.col.customer')}</Th>
                                <Th>{t('commissions.col.rate')}</Th>
                                <Th className="text-end">
                                    {t('commissions.col.eligible')}
                                </Th>
                                <Th className="text-end">
                                    {t('commissions.col.collected')}
                                </Th>
                                <Th className="text-end">
                                    {t('commissions.col.commission')}
                                </Th>
                                <Th className="text-end">
                                    {t('commissions.col.available')}
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {s.sales.length === 0 && (
                                <tr>
                                    <Td colSpan={7} className="text-weak">
                                        {t('commissions.no_sales')}
                                    </Td>
                                </tr>
                            )}
                            {s.sales.map((sale) => (
                                <tr key={sale.id} data-test="sale-row">
                                    <Td className="font-semibold">
                                        <Link
                                            href={OrderSaleController.show.url(
                                                sale.order.id,
                                            )}
                                            className="hover:underline"
                                        >
                                            <bdi dir="ltr">
                                                {sale.order.number}
                                            </bdi>
                                        </Link>
                                        {sale.status === 'superseded' && (
                                            <Badge
                                                variant="neutral"
                                                className="ms-2"
                                            >
                                                {t('commissions.superseded')}
                                            </Badge>
                                        )}
                                    </Td>
                                    <Td>{sale.order.customer}</Td>
                                    <Td>
                                        <Rate bp={sale.rate_bp} />
                                    </Td>
                                    <Td className="text-end">
                                        {sale.eligible_minor === null ? (
                                            '—'
                                        ) : (
                                            <Minor
                                                value={sale.eligible_minor}
                                            />
                                        )}
                                    </Td>
                                    <Td className="text-end">
                                        {sale.collected_minor === null ? (
                                            '—'
                                        ) : (
                                            <Minor
                                                value={sale.collected_minor}
                                            />
                                        )}
                                    </Td>
                                    <Td className="text-end">
                                        <Minor
                                            value={sale.accrued_minor}
                                            strong
                                        />
                                    </Td>
                                    <Td className="text-end">
                                        <Minor value={sale.available_minor} />
                                        {sale.hold && (
                                            <Badge
                                                variant="warning"
                                                className="ms-2"
                                            >
                                                {t(
                                                    `commissions.hold.${sale.hold}` as TranslationKey,
                                                )}
                                            </Badge>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </TableWrap>
                <p className="mt-2 text-caption text-weak">
                    {t('commissions.basis_hint')}
                </p>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <h2 className="text-heading mb-3 text-strong">
                        {t('commissions.payouts')}
                    </h2>
                    {s.payouts.length === 0 ? (
                        <p className="text-weak">
                            {t('commissions.no_payouts')}
                        </p>
                    ) : (
                        <ul className="grid gap-3" data-test="payouts">
                            {s.payouts.map((p) => (
                                <li
                                    key={p.id}
                                    className="grid gap-2 rounded-md border border-stroke-weak p-3"
                                    data-test="payout"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="flex items-center gap-2">
                                            <PayoutBadge status={p.status} />
                                            <span className="text-caption text-weak">
                                                {formatDate(
                                                    p.period_from,
                                                    locale,
                                                )}{' '}
                                                –{' '}
                                                {formatDate(
                                                    p.period_to,
                                                    locale,
                                                )}
                                            </span>
                                        </span>
                                        {p.amount_minor !== null && (
                                            <Minor
                                                value={
                                                    p.corrected_minor ??
                                                    p.amount_minor
                                                }
                                                strong
                                            />
                                        )}
                                    </div>
                                    {p.reference && (
                                        <p className="text-caption text-weak">
                                            {p.method &&
                                                t(
                                                    `commissions.method.${p.method}` as TranslationKey,
                                                )}{' '}
                                            · <bdi dir="ltr">{p.reference}</bdi>
                                            {p.paid_on &&
                                                ` · ${formatDate(p.paid_on, locale)}`}
                                        </p>
                                    )}
                                    {p.reason && (
                                        <p className="text-caption">
                                            {p.reason}
                                        </p>
                                    )}
                                    {p.files.map((f) =>
                                        f.url ? (
                                            <a
                                                key={f.uuid}
                                                href={f.url}
                                                className="text-caption text-brand hover:underline"
                                            >
                                                {f.name}
                                            </a>
                                        ) : null,
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {p.can.approve && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() => reserve(p)}
                                                loading={approving.busy}
                                            >
                                                {t(
                                                    'commissions.payout.reserve',
                                                )}
                                            </Button>
                                        )}
                                        {p.can.approve && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setOpen({ type: 'pay' })
                                                }
                                            >
                                                {t('commissions.payout.record')}
                                            </Button>
                                        )}
                                        {p.can.record && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'record',
                                                        id: p.id,
                                                    })
                                                }
                                            >
                                                {t('commissions.payout.record')}
                                            </Button>
                                        )}
                                        {p.can.cancel && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'cancel',
                                                        id: p.id,
                                                    })
                                                }
                                            >
                                                {t('commissions.payout.cancel')}
                                            </Button>
                                        )}
                                        {p.can.correct && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'correct',
                                                        id: p.id,
                                                    })
                                                }
                                            >
                                                {t(
                                                    'commissions.payout.correct',
                                                )}
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                <Card>
                    <h2 className="text-heading mb-3 text-strong">
                        {t('commissions.entries')}
                    </h2>
                    {s.entries.length === 0 ? (
                        <p className="text-weak">
                            {t('commissions.no_entries')}
                        </p>
                    ) : (
                        <ul className="grid gap-2" data-test="entries">
                            {s.entries.map((e) => (
                                <li
                                    key={e.id}
                                    className="flex items-start justify-between gap-3 border-b border-stroke-weak pb-2 last:border-0"
                                >
                                    <span className="min-w-0">
                                        <span className="block font-semibold">
                                            {t(entryKey(e))}
                                        </span>
                                        <span className="text-caption text-weak">
                                            <bdi dir="ltr">
                                                {e.order?.number}
                                            </bdi>{' '}
                                            · {formatDate(e.created_at, locale)}
                                            {e.reason && ` · ${e.reason}`}
                                        </span>
                                    </span>
                                    <span
                                        className={
                                            e.amount_minor > 0
                                                ? 'text-success-text'
                                                : undefined
                                        }
                                    >
                                        {e.amount_minor > 0 && '+'}
                                        <Minor value={e.amount_minor} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {open?.type === 'pay' && (
                <PayoutDialog
                    statement={s}
                    draft={draft}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('commissions.payout.done'))}
                />
            )}
            {open?.type === 'record' && payout(open.id) && (
                <RecordDialog
                    payout={payout(open.id)!}
                    today={s.today}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('commissions.payout.done'))}
                />
            )}
            {open?.type === 'cancel' && payout(open.id) && (
                <CancelDialog
                    payout={payout(open.id)!}
                    onClose={() => setOpen(null)}
                    onDone={(next) =>
                        done(next, t('commissions.payout.cancelled_done'))
                    }
                />
            )}
            {open?.type === 'correct' && payout(open.id) && (
                <CorrectDialog
                    payout={payout(open.id)!}
                    onClose={() => setOpen(null)}
                    onDone={(next) =>
                        done(next, t('commissions.payout.corrected_done'))
                    }
                />
            )}
        </>
    );
}

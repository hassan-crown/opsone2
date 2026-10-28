import { Head, Link, router } from '@inertiajs/react';
import { Ban, PackageCheck, Pencil, Scale, Send } from 'lucide-react';
import { useState } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import { Amount } from '@/components/opsone/amount';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { AdjustDialog } from '@/components/procurement/adjust-dialog';
import {
    BackLink,
    FormErrors,
    PurchaseStatusBadge,
    Qty,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { PurchaseDetail } from '@/types/procurement';

type Props = { purchase: PurchaseDetail };

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap justify-between gap-2 border-b border-stroke-weak py-2 last:border-0">
            <dt className="text-weak">{label}</dt>
            <dd className="font-semibold text-strong">{children}</dd>
        </div>
    );
}

export default function PurchaseShow({ purchase }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();
    const [adjusting, setAdjusting] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const costs = purchase.costs_visible;
    const showCancelled = purchase.lines.some((l) => l.cancelled_milli > 0);
    const can = purchase.can;

    const confirm = () => {
        setConfirming(true);
        router.post(
            PurchaseController.confirm.url({ purchase: purchase.id }),
            { lock_version: purchase.lock_version, idempotency_key: key },
            {
                preserveScroll: true,
                onStart: () => setErrors({}),
                onError: (e) => {
                    setErrors(e);
                    renew();
                },
                onFinish: () => setConfirming(false),
            },
        );
    };

    return (
        <>
            <Head title={purchase.number} />
            <PageHeader
                title={purchase.number}
                description={purchase.supplier.name.label}
                back={
                    <BackLink
                        href={PurchaseController.index.url()}
                        label={t('procurement.title')}
                    />
                }
                actions={
                    <>
                        {can.edit && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={PurchaseController.edit.url({
                                        purchase: purchase.id,
                                    })}
                                >
                                    <Icon icon={Pencil} />
                                    {t('procurement.edit')}
                                </Link>
                            </Button>
                        )}
                        {can.confirm && (
                            <Button
                                type="button"
                                loading={confirming}
                                disabled={confirming}
                                onClick={confirm}
                                data-test="confirm-order"
                            >
                                <Icon icon={Send} />
                                {t('procurement.confirm')}
                            </Button>
                        )}
                        {can.receive && (
                            <Button asChild data-test="receive-order">
                                <Link
                                    href={PurchaseController.receiveScreen.url({
                                        purchase: purchase.id,
                                    })}
                                >
                                    <Icon icon={PackageCheck} />
                                    {t('procurement.receive')}
                                </Link>
                            </Button>
                        )}
                        {can.adjust && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setAdjusting(true)}
                                data-test="adjust-order"
                            >
                                <Icon icon={Scale} />
                                {t('procurement.adjust')}
                            </Button>
                        )}
                        {can.cancel && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={PurchaseController.cancelScreen.url({
                                        purchase: purchase.id,
                                    })}
                                >
                                    <Icon icon={Ban} />
                                    {t('procurement.cancel_remainder')}
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />
            <div className="grid gap-4">
                <FormErrors errors={errors} />
                {purchase.status === 'cancelled_remainder' && (
                    <Alert variant="warning">
                        <AlertBody>
                            <p className="font-semibold">
                                {t('procurement.cancelled_note')}
                            </p>
                            {purchase.cancel_reason && (
                                <p>{purchase.cancel_reason}</p>
                            )}
                        </AlertBody>
                    </Alert>
                )}
                <Card>
                    <dl className="grid gap-x-8 md:grid-cols-2">
                        <Fact label={t('procurement.col_status')}>
                            <PurchaseStatusBadge status={purchase.status} />
                        </Fact>
                        <Fact label={t('procurement.col_supplier')}>
                            {purchase.supplier.name.label}
                        </Fact>
                        <Fact label={t('procurement.col_branch')}>
                            {purchase.branch.name}
                        </Fact>
                        <Fact label={t('procurement.location')}>
                            {purchase.location_id !== null
                                ? t('procurement.location_ref', {
                                      id: purchase.location_id,
                                  })
                                : '—'}
                        </Fact>
                        <Fact label={t('procurement.col_expected')}>
                            {purchase.expected_on
                                ? formatDate(purchase.expected_on, locale)
                                : '—'}
                        </Fact>
                        <Fact label={t('procurement.responsible')}>
                            {purchase.responsible?.name ?? '—'}
                        </Fact>
                        {purchase.ordered_at && (
                            <Fact label={t('procurement.ordered_at')}>
                                {formatDateTime(purchase.ordered_at, locale)}
                            </Fact>
                        )}
                        {purchase.notes && (
                            <Fact label={t('procurement.notes')}>
                                {purchase.notes}
                            </Fact>
                        )}
                    </dl>
                </Card>

                <Card className="gap-0 p-0 md:p-0">
                    <h2 className="text-heading px-4 pt-4 text-strong md:px-6 md:pt-6">
                        {t('procurement.items')}
                    </h2>
                    <TableWrap className="mt-3">
                        <Table caption={t('procurement.lines_caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('procurement.col_item')}</Th>
                                    <Th>{t('procurement.col_ordered')}</Th>
                                    <Th>{t('procurement.col_accepted')}</Th>
                                    <Th>{t('procurement.col_rejected')}</Th>
                                    <Th>{t('procurement.col_remaining')}</Th>
                                    {showCancelled && (
                                        <Th>
                                            {t('procurement.col_cancelled')}
                                        </Th>
                                    )}
                                    {costs && (
                                        <>
                                            <Th className="text-end">
                                                {t('procurement.unit_cost')}
                                            </Th>
                                            <Th className="text-end">
                                                {t('procurement.col_gross')}
                                            </Th>
                                        </>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {purchase.lines.map((l) => (
                                    <tr key={l.id} data-test="line-row">
                                        <Td className="font-semibold">
                                            {l.name.label}
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.quantity_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.accepted_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.rejected_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.remaining_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        {showCancelled && (
                                            <Td>
                                                <Qty
                                                    milli={l.cancelled_milli}
                                                    unit={l.unit}
                                                />
                                            </Td>
                                        )}
                                        {costs && (
                                            <>
                                                <Td className="text-end">
                                                    {l.unit_cost_minor ==
                                                    null ? (
                                                        '—'
                                                    ) : (
                                                        <Amount
                                                            value={
                                                                l.unit_cost_minor /
                                                                100
                                                            }
                                                        />
                                                    )}
                                                </Td>
                                                <Td className="text-end">
                                                    {l.gross_minor == null ? (
                                                        '—'
                                                    ) : (
                                                        <Amount
                                                            value={
                                                                l.gross_minor /
                                                                100
                                                            }
                                                        />
                                                    )}
                                                </Td>
                                            </>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                    {costs && purchase.totals && purchase.vat && (
                        <dl
                            className="m-4 ms-auto grid w-full max-w-sm gap-1 md:m-6"
                            data-test="totals"
                        >
                            <Fact label={t('procurement.net')}>
                                <Amount
                                    value={purchase.totals.net_minor / 100}
                                />
                            </Fact>
                            <Fact
                                label={t(
                                    purchase.vat.inclusive
                                        ? 'procurement.vat_inclusive'
                                        : 'procurement.vat_exclusive',
                                    {
                                        rate: formatNumber(
                                            purchase.vat.rate_bp / 100,
                                        ),
                                    },
                                )}
                            >
                                <Amount
                                    value={purchase.totals.vat_minor / 100}
                                />
                            </Fact>
                            <Fact label={t('procurement.col_gross')}>
                                <Amount
                                    value={purchase.totals.gross_minor / 100}
                                    strong
                                />
                            </Fact>
                        </dl>
                    )}
                </Card>

                <section aria-labelledby="receipts" className="grid gap-3">
                    <h2 id="receipts" className="text-heading text-strong">
                        {t('procurement.receipts')}
                    </h2>
                    {purchase.receipts.length === 0 ? (
                        <p className="text-body text-weak">
                            {t('procurement.receipts_empty')}
                        </p>
                    ) : (
                        purchase.receipts.map((r) => (
                            <Card
                                key={r.id}
                                data-test="receipt"
                                className="gap-3"
                            >
                                <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                    <span className="font-semibold text-strong">
                                        {formatDate(r.received_on, locale)}
                                    </span>
                                    {r.reference && (
                                        <span className="text-body text-weak">
                                            {t('procurement.reference')}:{' '}
                                            <bdi dir="ltr">{r.reference}</bdi>
                                        </span>
                                    )}
                                    {r.receiver && (
                                        <span className="text-body text-weak">
                                            {t('procurement.receiver')}:{' '}
                                            {r.receiver}
                                        </span>
                                    )}
                                </div>
                                {r.note && (
                                    <p className="text-body">{r.note}</p>
                                )}
                                <TableWrap>
                                    <Table
                                        caption={t(
                                            'procurement.receipt_caption',
                                        )}
                                        className="min-w-md"
                                    >
                                        <thead>
                                            <tr>
                                                <Th>
                                                    {t('procurement.col_item')}
                                                </Th>
                                                <Th>
                                                    {t(
                                                        'procurement.col_accepted',
                                                    )}
                                                </Th>
                                                <Th>
                                                    {t(
                                                        'procurement.col_rejected',
                                                    )}
                                                </Th>
                                                <Th>
                                                    {t(
                                                        'procurement.reject_reason',
                                                    )}
                                                </Th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {r.lines.map((rl, i) => {
                                                const line =
                                                    purchase.lines.find(
                                                        (l) =>
                                                            l.line_no ===
                                                            rl.line_no,
                                                    );

                                                return (
                                                    <tr key={i}>
                                                        <Td>
                                                            {line?.name.label ??
                                                                rl.line_no ??
                                                                '—'}
                                                        </Td>
                                                        <Td>
                                                            <Qty
                                                                milli={
                                                                    rl.accepted_milli
                                                                }
                                                                unit={
                                                                    line?.unit ??
                                                                    ''
                                                                }
                                                            />
                                                        </Td>
                                                        <Td>
                                                            <Qty
                                                                milli={
                                                                    rl.rejected_milli
                                                                }
                                                                unit={
                                                                    line?.unit ??
                                                                    ''
                                                                }
                                                            />
                                                        </Td>
                                                        <Td>
                                                            {rl.reject_reason ??
                                                                '—'}
                                                        </Td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </Table>
                                </TableWrap>
                                {r.files.length > 0 && (
                                    <div>
                                        <p className="text-label text-weak">
                                            {t('procurement.proofs')}
                                        </p>
                                        <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                            {r.files.map((f) => (
                                                <li key={f.uuid}>
                                                    {f.url ? (
                                                        <a
                                                            href={f.url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-link hover:underline"
                                                        >
                                                            {f.name}
                                                        </a>
                                                    ) : (
                                                        <span className="text-weak">
                                                            {f.name}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </Card>
                        ))
                    )}
                </section>
            </div>
            {can.adjust && (
                <AdjustDialog
                    key={purchase.lock_version}
                    purchase={purchase}
                    open={adjusting}
                    onOpenChange={setAdjusting}
                />
            )}
        </>
    );
}

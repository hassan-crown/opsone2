import { Head, Link, router } from '@inertiajs/react';
import {
    EyeOff,
    FileSignature,
    GitBranch,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { lazy, Suspense, useState } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/Agreements/AgreementController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import OrderLineController from '@/actions/App/Http/Controllers/Orders/OrderLineController';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import { Amount } from '@/components/opsone/amount';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { BranchTransferDialog } from '@/components/orders/branch-transfer-dialog';
import { ConflictAlert } from '@/components/orders/conflict';
import { EditOrderDialog } from '@/components/orders/edit-dialog';
import { LinePicker } from '@/components/orders/line-picker';
import { lineName, optionsText } from '@/components/orders/lines';
import { KindBadge, StatusBadge } from '@/components/orders/status';
import { toPayload } from '@/components/orders/types';
import type {
    HistoryEntry,
    Option,
    OrderDetail,
} from '@/components/orders/types';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    order: OrderDetail;
    history: HistoryEntry[];
    members: Option[];
    invoiceTypes: string[];
    transferBranches: Option[];
};

// Loaded on demand: keeps the page's preload `Link` header (one entry per static chunk) within nginx's buffer.
const OrderStages = lazy(() =>
    import('@/components/flow/order-stages').then((m) => ({
        default: m.OrderStages,
    })),
);

const EDITABLE = ['in_progress', 'paused', 'draft'];

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

/** OrderOverview + OrderStates (history) for one order; every action re-checks access on the server. */
export default function OrderShow({
    order,
    history,
    members,
    invoiceTypes,
    transferBranches,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [editing, setEditing] = useState(false);
    const [transferring, setTransferring] = useState(false);
    const [adding, setAdding] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [addError, setAddError] = useState<string | null>(null);
    const canEdit = order.can.edit && EDITABLE.includes(order.status);
    const hidden = order.financials_hidden;
    const activeLines = order.lines.filter((l) => !l.removed);

    const removeLine = async (lineId: number) => {
        setError(null);
        const result = await api(
            'DELETE',
            OrderLineController.destroy.url({ order: order.id, line: lineId }),
            {
                lock_version: order.lock_version,
            },
        );
        if (result.ok) {
            router.reload();

            return;
        }
        if (result.stale) {
            setConflict(true);
        } else {
            setError(result.message ?? t('orders.lines.remove_failed'));
        }
    };

    const addLine = async (line: Parameters<typeof toPayload>[0]) => {
        setAddError(null);
        const result = await api(
            'POST',
            OrderLineController.store.url(order.id),
            {
                lock_version: order.lock_version,
                ...toPayload(line),
            },
        );
        if (result.ok) {
            notify('success', { title: t('orders.lines.added') });
            setAdding(false);
            router.reload();

            return;
        }
        if (result.stale) {
            setAdding(false);
            setConflict(true);

            return;
        }
        setAddError(
            Object.values(result.errors)[0]?.[0] ??
                result.message ??
                t('orders.lines.add_failed'),
        );
    };

    return (
        <>
            <Head title={order.number} />
            <PageHeader
                title={order.number}
                description={`${order.customer.name} · ${formatDate(order.created_at, locale)}`}
                actions={
                    <>
                        {order.kind === 'custom' && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={AgreementController.show.url(
                                        order.id,
                                    )}
                                    data-test="open-agreement"
                                >
                                    <Icon icon={FileSignature} />
                                    {t('agreements.title')}
                                </Link>
                            </Button>
                        )}
                        {order.can.transfer_branch &&
                            transferBranches.length > 0 && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setTransferring(true)}
                                    data-test="transfer-order"
                                >
                                    <Icon icon={GitBranch} />
                                    {t('orders.transfer.action')}
                                </Button>
                            )}
                        {canEdit && (
                            <Button
                                type="button"
                                onClick={() => setEditing(true)}
                                data-test="edit-order"
                            >
                                <Icon icon={Pencil} />
                                {t('orders.edit.action')}
                            </Button>
                        )}
                    </>
                }
            />

            <div
                className="mb-4 flex flex-wrap items-center gap-2"
                data-test="order-header"
            >
                <KindBadge kind={order.kind} />
                <StatusBadge status={order.status} />
                <span className="text-weak">
                    {t('orders.field.branch')}:{' '}
                    <span className="font-semibold text-strong">
                        {order.branch.name}
                    </span>
                </span>
                <span className="text-weak">
                    {t('orders.field.assignee')}:{' '}
                    <span className="font-semibold text-strong">
                        {order.assignee?.name ?? t('orders.unassigned')}
                    </span>
                </span>
                {order.purchase_reference && (
                    <Badge variant="info">
                        <bdi dir="ltr">{order.purchase_reference}</bdi>
                    </Badge>
                )}
            </div>

            {conflict && (
                <div className="mb-4">
                    <ConflictAlert onReload={() => setConflict(false)} />
                </div>
            )}
            {error && (
                <Alert variant="danger" className="mb-4" role="alert">
                    <AlertBody>{error}</AlertBody>
                </Alert>
            )}

            <Tabs defaultValue="overview" className="min-w-0">
                <TabsList>
                    <TabsTrigger value="overview">
                        {t('orders.tab.overview')}
                    </TabsTrigger>
                    {order.kind === 'custom' && (
                        <TabsTrigger value="progress" data-test="tab-progress">
                            {t('progress.tab')}
                        </TabsTrigger>
                    )}
                    <TabsTrigger value="history" data-test="tab-history">
                        {t('orders.tab.history')}
                    </TabsTrigger>
                </TabsList>

                {order.kind === 'custom' && (
                    <TabsContent value="progress" className="min-w-0">
                        <Suspense
                            fallback={<Skeleton className="h-40 w-full" />}
                        >
                            <OrderStages
                                orderId={order.id}
                                orderStatus={order.status}
                                onChanged={() => router.reload()}
                            />
                        </Suspense>
                    </TabsContent>
                )}

                <TabsContent value="overview" className="min-w-0">
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
                        <div className="grid min-w-0 content-start gap-4">
                            {order.kind === 'custom' && (
                                <Card data-test="order-need">
                                    <h2 className="text-heading mb-2 text-strong">
                                        {t('orders.field.need_summary')}
                                    </h2>
                                    <p className="text-body whitespace-pre-line">
                                        {order.need_summary}
                                    </p>
                                    <dl className="mt-3">
                                        <Fact
                                            label={t(
                                                'orders.field.needs_measurement',
                                            )}
                                        >
                                            {order.needs_measurement
                                                ? t('orders.yes')
                                                : t('orders.no')}
                                        </Fact>
                                        <Fact
                                            label={t(
                                                'orders.field.needs_design',
                                            )}
                                        >
                                            {order.needs_design
                                                ? t('orders.yes')
                                                : t('orders.no')}
                                        </Fact>
                                    </dl>
                                </Card>
                            )}

                            <Card
                                className="gap-0 p-0 md:p-0"
                                data-test="order-lines"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 p-4">
                                    <div>
                                        <h2 className="text-heading text-strong">
                                            {t('orders.lines.title')}
                                        </h2>
                                        <p className="text-caption text-weak">
                                            {t('orders.lines.frozen')}
                                        </p>
                                    </div>
                                    {canEdit && (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => {
                                                setAddError(null);
                                                setAdding(true);
                                            }}
                                            data-test="add-line-open"
                                        >
                                            <Icon icon={Plus} />
                                            {t('orders.lines.add')}
                                        </Button>
                                    )}
                                </div>
                                {order.lines.length === 0 ? (
                                    <p className="px-4 pb-4 text-weak">
                                        {t('orders.lines.empty')}
                                    </p>
                                ) : (
                                    <TableWrap>
                                        <Table
                                            caption={t('orders.lines.title')}
                                        >
                                            <thead>
                                                <tr>
                                                    <Th>
                                                        {t('orders.lines.item')}
                                                    </Th>
                                                    <Th>
                                                        {t(
                                                            'orders.lines.quantity',
                                                        )}
                                                    </Th>
                                                    {!hidden && (
                                                        <Th>
                                                            {t(
                                                                'orders.lines.unit_price',
                                                            )}
                                                        </Th>
                                                    )}
                                                    {!hidden && (
                                                        <Th>
                                                            {t(
                                                                'orders.lines.total',
                                                            )}
                                                        </Th>
                                                    )}
                                                    <Th className="relative">
                                                        <span className="sr-only">
                                                            {t(
                                                                'orders.lines.actions',
                                                            )}
                                                        </span>
                                                    </Th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {order.lines.map((l) => (
                                                    <tr
                                                        key={l.id}
                                                        data-test="order-line"
                                                        className={
                                                            l.removed
                                                                ? 'opacity-60'
                                                                : undefined
                                                        }
                                                    >
                                                        <Td>
                                                            <p
                                                                className={
                                                                    l.removed
                                                                        ? 'font-semibold line-through'
                                                                        : 'font-semibold text-strong'
                                                                }
                                                            >
                                                                {lineName(
                                                                    l,
                                                                    locale,
                                                                )}{' '}
                                                                {l.snapshot
                                                                    .version !==
                                                                    undefined && (
                                                                    <Badge
                                                                        variant="neutral"
                                                                        data-test="line-version"
                                                                    >
                                                                        {t(
                                                                            'orders.version',
                                                                            {
                                                                                n: l
                                                                                    .snapshot
                                                                                    .version,
                                                                            },
                                                                        )}
                                                                    </Badge>
                                                                )}
                                                                {l.removed && (
                                                                    <Badge variant="warning">
                                                                        {t(
                                                                            'orders.lines.removed',
                                                                        )}
                                                                    </Badge>
                                                                )}
                                                            </p>
                                                            {optionsText(
                                                                l,
                                                                locale,
                                                            ) && (
                                                                <p className="text-caption text-weak">
                                                                    {optionsText(
                                                                        l,
                                                                        locale,
                                                                    )}
                                                                </p>
                                                            )}
                                                        </Td>
                                                        <Td>
                                                            <bdi dir="ltr">
                                                                {l.quantity}
                                                            </bdi>
                                                            {l.unit
                                                                ? ` ${l.unit}`
                                                                : ''}
                                                        </Td>
                                                        {!hidden && (
                                                            <Td>
                                                                {l.unit_price_minor ==
                                                                null ? (
                                                                    <span className="text-weak">
                                                                        {t(
                                                                            'orders.price_unknown',
                                                                        )}
                                                                    </span>
                                                                ) : (
                                                                    <Amount
                                                                        value={
                                                                            l.unit_price_minor /
                                                                            100
                                                                        }
                                                                    />
                                                                )}
                                                            </Td>
                                                        )}
                                                        {!hidden && (
                                                            <Td>
                                                                {l.line_total_minor ==
                                                                null ? (
                                                                    <span className="text-weak">
                                                                        —
                                                                    </span>
                                                                ) : (
                                                                    <Amount
                                                                        value={
                                                                            l.line_total_minor /
                                                                            100
                                                                        }
                                                                    />
                                                                )}
                                                            </Td>
                                                        )}
                                                        <Td>
                                                            {canEdit &&
                                                                !l.removed && (
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon-sm"
                                                                        aria-label={t(
                                                                            'orders.lines.remove',
                                                                        )}
                                                                        onClick={() =>
                                                                            void removeLine(
                                                                                l.id,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Icon
                                                                            icon={
                                                                                Trash2
                                                                            }
                                                                        />
                                                                    </Button>
                                                                )}
                                                        </Td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </Table>
                                    </TableWrap>
                                )}
                            </Card>
                        </div>

                        <div className="grid min-w-0 content-start gap-4">
                            <Card data-test="order-money">
                                <h2 className="text-heading mb-2 text-strong">
                                    {t('orders.money.title')}
                                </h2>
                                {hidden ? (
                                    <p
                                        className="flex items-center gap-2 text-weak"
                                        data-test="money-hidden"
                                    >
                                        <Icon icon={EyeOff} />
                                        {t('orders.money_hidden')}
                                    </p>
                                ) : order.total_minor == null ? (
                                    <p
                                        className="text-weak"
                                        data-test="money-unknown"
                                    >
                                        {t('orders.money.not_priced')}
                                    </p>
                                ) : (
                                    <p
                                        className="text-title"
                                        data-test="money-total"
                                    >
                                        <Amount
                                            value={order.total_minor / 100}
                                            strong
                                        />
                                    </p>
                                )}
                                {!hidden && (
                                    <p className="mt-1 text-caption text-weak">
                                        {t('orders.money.lines_count', {
                                            n: activeLines.length,
                                        })}
                                    </p>
                                )}
                            </Card>

                            <Card>
                                <h2 className="text-heading mb-2 text-strong">
                                    {t('orders.customer')}
                                </h2>
                                <Link
                                    href={CustomerController.show(
                                        order.customer.id,
                                    )}
                                    className="font-semibold text-link hover:underline"
                                >
                                    {order.customer.name}
                                </Link>
                                {order.contact && (
                                    <p className="text-caption text-weak">
                                        {order.contact.name} ·{' '}
                                        <bdi dir="ltr">
                                            {order.contact.mobile}
                                        </bdi>
                                    </p>
                                )}
                                <dl className="mt-3">
                                    <Fact
                                        label={t('orders.field.invoice_type')}
                                    >
                                        {order.invoice_type
                                            ? t(
                                                  `orders.invoice.${order.invoice_type}` as TranslationKey,
                                              )
                                            : t('orders.invoice.none')}
                                    </Fact>
                                </dl>
                            </Card>

                            {order.related_orders.length > 0 && (
                                <Card data-test="related-orders">
                                    <h2 className="text-heading mb-2 text-strong">
                                        {t('orders.related')}
                                    </h2>
                                    <ul className="grid gap-1">
                                        {order.related_orders.map((r) => (
                                            <li
                                                key={r.id}
                                                className="flex items-center gap-2"
                                            >
                                                <Link
                                                    href={OrderController.show(
                                                        r.id,
                                                    )}
                                                    className="font-semibold text-link hover:underline"
                                                >
                                                    <bdi dir="ltr">
                                                        {r.number}
                                                    </bdi>
                                                </Link>
                                                <KindBadge kind={r.kind} />
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            )}
                        </div>
                    </div>
                </TabsContent>

                <TabsContent value="history" className="min-w-0">
                    <Card data-test="order-history">
                        <h2 className="text-heading mb-1 text-strong">
                            {t('orders.history.title')}
                        </h2>
                        <p className="mb-4 text-caption text-weak">
                            {t('orders.history.desc')}
                        </p>
                        {history.length === 0 ? (
                            <p className="text-weak">
                                {t('orders.history.empty')}
                            </p>
                        ) : (
                            <ol className="grid gap-3">
                                {history.map((h) => (
                                    <li
                                        key={h.id}
                                        className="rounded-lg bg-surface p-3 ring-1 ring-stroke-weak"
                                        data-test="history-entry"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            {h.from && (
                                                <>
                                                    <StatusBadge
                                                        status={h.from}
                                                    />
                                                    <span aria-hidden="true">
                                                        {locale === 'ar'
                                                            ? '←'
                                                            : '→'}
                                                    </span>
                                                </>
                                            )}
                                            <StatusBadge status={h.to} />
                                            <span className="ms-auto text-caption text-weak">
                                                {formatDate(h.at, locale, {
                                                    dateStyle: 'medium',
                                                    timeStyle: 'short',
                                                })}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-caption text-weak">
                                            {h.actor ??
                                                t('orders.history.system')}
                                            {h.reason ? ` · ${h.reason}` : ''}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>
                </TabsContent>
            </Tabs>

            {canEdit && editing && (
                <EditOrderDialog
                    open={editing}
                    onOpenChange={setEditing}
                    order={order}
                    members={members}
                    invoiceTypes={invoiceTypes}
                />
            )}
            {order.can.transfer_branch && (
                <BranchTransferDialog
                    open={transferring}
                    onOpenChange={setTransferring}
                    order={order}
                    branches={transferBranches}
                    members={members}
                />
            )}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent size="md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('orders.lines.add')}</DialogTitle>
                        <DialogDescription className="sr-only">
                            {t('orders.lines.frozen')}
                        </DialogDescription>
                    </DialogHeader>
                    {addError && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{addError}</AlertBody>
                        </Alert>
                    )}
                    <LinePicker
                        kind={order.kind}
                        branchId={order.branch.id}
                        onAdd={(l) => void addLine(l)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs: [{ title: 'orders.nav', href: OrderController.index() }],
};

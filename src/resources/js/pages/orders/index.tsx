import { Head, Link, router, usePage } from '@inertiajs/react';
import { ClipboardList, ListFilter, Plus } from 'lucide-react';
import { useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { FilterBar } from '@/components/customers/filter-bar';
import { Pager } from '@/components/customers/pager';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { KindBadge, StageBadge, StatusBadge } from '@/components/orders/status';
import type { Meta, Option, OrderRow } from '@/components/orders/types';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import {
    BlockedAction,
    useLicenseBlock,
} from '@/components/opsone/blocked-action';

type Filters = {
    q: string;
    kind: string;
    status: string;
    branch: string;
    assignee: string;
};

type Props = {
    orders: OrderRow[];
    meta: Meta;
    filters: Filters;
    members: Option[];
    branches: Option[];
    statuses: string[];
    canCreate: boolean;
};

function OrderMoney({ order }: { order: OrderRow }) {
    const t = useT();

    if (order.financials_hidden) {
        return (
            <span className="text-weak" title={t('orders.money_hidden')}>
                {t('orders.money_hidden_short')}
            </span>
        );
    }
    if (order.total_minor == null) {
        return <span className="text-weak">{t('orders.price_unknown')}</span>;
    }

    return <Amount value={order.total_minor / 100} />;
}

/** OrdersList / OrdersMobile / OrdersEmpty / OrdersNoResults: filters live in the URL, scope is enforced by the server. */
export default function OrdersIndex({
    orders,
    meta,
    filters,
    members,
    branches,
    statuses,
    canCreate,
}: Props) {
    const t = useT();
    const blocked = useLicenseBlock('orders_create');
    const { locale } = useLocale();
    const { auth } = usePage().props;
    const url = OrderController.index.url();
    const filtered = Object.values(filters).some((v) => v !== '');
    const [filtersOpen, setFiltersOpen] = useState(false);
    // Filters set in the panel (the quick chips stay visible on their own).
    const panelCount = (['q', 'status', 'branch', 'assignee'] as const).filter(
        (k) => filters[k] !== '',
    ).length;
    const showMoney = orders.some((o) => !o.financials_hidden);

    const go = (next: Partial<Filters>) =>
        router.get(
            url,
            Object.fromEntries(
                Object.entries({ ...filters, ...next, page: '' }).filter(
                    ([, v]) => v !== '' && v !== undefined,
                ),
            ),
            { preserveScroll: true },
        );

    const kinds: { value: string; label: string }[] = [
        { value: '', label: t('orders.filter.all') },
        { value: 'custom', label: t('orders.kind.custom') },
        { value: 'ready', label: t('orders.kind.ready') },
    ];
    const quick = [
        {
            key: 'mine',
            label: t('orders.quick.mine'),
            value: String(auth.user.id),
        },
        { key: 'none', label: t('orders.quick.unassigned'), value: 'none' },
    ];

    const filterBar = (
        <FilterBar
            url={url}
            initial={{
                q: filters.q,
                status: filters.status,
                branch: filters.branch,
                assignee: filters.assignee,
            }}
            extra={{ kind: filters.kind }}
            searchLabel="orders.search"
            selects={[
                {
                    name: 'status',
                    label: t('orders.filter.status'),
                    options: [
                        { value: '', label: t('orders.filter.all') },
                        ...statuses.map((s) => ({
                            value: s,
                            label: t(`orders.status.${s}` as TranslationKey),
                        })),
                    ],
                },
                {
                    name: 'branch',
                    label: t('orders.filter.branch'),
                    options: [
                        { value: '', label: t('orders.filter.all') },
                        ...branches.map((b) => ({
                            value: String(b.id),
                            label: b.name,
                        })),
                    ],
                },
                {
                    name: 'assignee',
                    label: t('orders.filter.assignee'),
                    options: [
                        { value: '', label: t('orders.filter.all') },
                        {
                            value: 'none',
                            label: t('orders.filter.unassigned'),
                        },
                        ...members.map((m) => ({
                            value: String(m.id),
                            label: m.name,
                        })),
                    ],
                },
            ]}
        />
    );

    return (
        <>
            <Head title={t('orders.title')} />
            <PageHeader
                title={t('orders.title')}
                description={t('orders.description')}
                actions={
                    blocked ? (
                        <BlockedAction
                            label={t('orders.add')}
                            reason={blocked}
                            testId="add-order"
                        />
                    ) : (
                        canCreate && (
                            <Button asChild data-test="add-order">
                                <Link href={OrderController.create()}>
                                    <Icon icon={Plus} />
                                    {t('orders.add')}
                                </Link>
                            </Button>
                        )
                    )
                }
            />

            <div
                className="mb-3 flex flex-wrap items-center gap-2"
                data-test="order-quick-filters"
            >
                <div
                    role="group"
                    aria-label={t('orders.filter.kind')}
                    className="flex flex-wrap gap-2"
                >
                    {kinds.map((k) => (
                        <Button
                            key={k.value}
                            type="button"
                            size="sm"
                            variant={
                                filters.kind === k.value
                                    ? 'default'
                                    : 'secondary'
                            }
                            aria-pressed={filters.kind === k.value}
                            onClick={() => go({ kind: k.value })}
                        >
                            {k.label}
                        </Button>
                    ))}
                </div>
                <span
                    className="mx-1 hidden h-6 w-px bg-stroke-weak sm:block"
                    aria-hidden="true"
                />
                {quick.map((q) => (
                    <Button
                        key={q.key}
                        type="button"
                        size="sm"
                        variant={
                            filters.assignee === q.value
                                ? 'default'
                                : 'secondary'
                        }
                        aria-pressed={filters.assignee === q.value}
                        onClick={() =>
                            go({
                                assignee:
                                    filters.assignee === q.value ? '' : q.value,
                            })
                        }
                    >
                        {q.label}
                    </Button>
                ))}
            </div>

            <div className="mb-3 md:hidden">
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={() => setFiltersOpen(true)}
                    data-test="orders-filter-open"
                >
                    <Icon icon={ListFilter} />
                    {panelCount > 0
                        ? t('orders.filter.open_count', { n: panelCount })
                        : t('orders.filter.open')}
                </Button>
            </div>
            <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
                <SheetContent
                    side="bottom"
                    className="max-h-[85dvh] overflow-y-auto"
                >
                    <SheetHeader>
                        <SheetTitle>{t('orders.filter.title')}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {t('orders.description')}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="px-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                        {filterBar}
                    </div>
                </SheetContent>
            </Sheet>
            <div className="hidden md:block">{filterBar}</div>

            <Card className="gap-0 p-0 md:p-0">
                {orders.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title={
                            filtered
                                ? t('orders.no_results')
                                : t('orders.empty')
                        }
                        description={
                            filtered
                                ? t('orders.no_results_desc')
                                : t('orders.empty_desc')
                        }
                    />
                ) : (
                    <>
                        <ul
                            className="divide-y divide-stroke-weak md:hidden"
                            data-test="order-cards"
                        >
                            {orders.map((o) => (
                                <li key={o.id}>
                                    <Link
                                        href={OrderController.show(o.id)}
                                        className="flex flex-col gap-2 p-4 active:bg-fill"
                                        data-test="order-card"
                                    >
                                        <span className="flex items-center justify-between gap-3">
                                            <span className="flex min-w-0 items-center gap-2">
                                                <bdi
                                                    dir="ltr"
                                                    className="font-semibold whitespace-nowrap text-link"
                                                >
                                                    {o.number}
                                                </bdi>
                                                <KindBadge kind={o.kind} />
                                            </span>
                                            {showMoney && (
                                                <span className="shrink-0">
                                                    <OrderMoney order={o} />
                                                </span>
                                            )}
                                        </span>
                                        <span className="min-w-0 font-semibold break-words text-strong">
                                            {o.customer.name}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-2">
                                            {o.stage ? (
                                                <StageBadge stage={o.stage} />
                                            ) : (
                                                <StatusBadge
                                                    status={o.status}
                                                />
                                            )}
                                            <span className="text-caption text-weak">
                                                {o.assignee?.name ??
                                                    t('orders.unassigned')}
                                                {' · '}
                                                {formatDate(
                                                    o.created_at,
                                                    locale,
                                                    {
                                                        dateStyle: 'medium',
                                                    },
                                                )}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <TableWrap className="max-md:hidden">
                            <Table caption={t('orders.caption')}>
                                <thead>
                                    <tr>
                                        <Th>{t('orders.col.number')}</Th>
                                        <Th>{t('orders.col.customer')}</Th>
                                        <Th>{t('orders.col.stage')}</Th>
                                        <Th>{t('orders.col.status')}</Th>
                                        <Th>{t('orders.col.branch')}</Th>
                                        <Th>{t('orders.col.assignee')}</Th>
                                        {showMoney && (
                                            <Th>{t('orders.col.total')}</Th>
                                        )}
                                        <Th>{t('orders.col.created')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.map((o) => (
                                        <tr key={o.id} data-test="order-row">
                                            <Td className="whitespace-nowrap">
                                                <Link
                                                    href={OrderController.show(
                                                        o.id,
                                                    )}
                                                    className="font-semibold text-link hover:underline"
                                                >
                                                    <bdi dir="ltr">
                                                        {o.number}
                                                    </bdi>
                                                </Link>{' '}
                                                <KindBadge kind={o.kind} />
                                            </Td>
                                            <Td>{o.customer.name}</Td>
                                            <Td>
                                                <StageBadge stage={o.stage} />
                                            </Td>
                                            <Td>
                                                <StatusBadge
                                                    status={o.status}
                                                />
                                            </Td>
                                            <Td>{o.branch.name}</Td>
                                            <Td>
                                                {o.assignee?.name ??
                                                    t('orders.unassigned')}
                                            </Td>
                                            {showMoney && (
                                                <Td>
                                                    <OrderMoney order={o} />
                                                </Td>
                                            )}
                                            <Td className="whitespace-nowrap">
                                                {formatDate(
                                                    o.created_at,
                                                    locale,
                                                    { dateStyle: 'medium' },
                                                )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                        <Pager meta={meta} url={url} query={filters} />
                    </>
                )}
            </Card>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [{ title: 'orders.nav', href: OrderController.index() }],
};

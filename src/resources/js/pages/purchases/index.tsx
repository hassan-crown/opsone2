import { Head, Link } from '@inertiajs/react';
import { PackageCheck, Plus } from 'lucide-react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import { FilterBar, Pager } from '@/components/catalog/shared';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import {
    ProcurementTabs,
    PurchaseStatusBadge,
} from '@/components/procurement/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    Page,
    PurchaseRow,
    PurchaseStatus,
    SupplierOption,
} from '@/types/procurement';

type Props = {
    orders: Page<PurchaseRow>;
    filters: { q: string; status: string | null; supplier: number | null };
    suppliers: SupplierOption[];
    canCreate: boolean;
    canManageSuppliers: boolean;
};

const STATUSES: PurchaseStatus[] = [
    'draft',
    'ordered',
    'partially_received',
    'received',
    'cancelled_remainder',
];

export default function PurchasesIndex({
    orders,
    filters,
    suppliers,
    canCreate,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const filtered = Boolean(filters.q || filters.status || filters.supplier);
    const showCosts = orders.data.some((o) => o.costs_visible);

    return (
        <>
            <Head title={t('procurement.title')} />
            <PageHeader
                title={t('procurement.title')}
                description={t('procurement.description')}
                actions={
                    canCreate && (
                        <Button asChild data-test="add-purchase">
                            <Link href={PurchaseController.create.url()}>
                                <Icon icon={Plus} />
                                {t('procurement.add')}
                            </Link>
                        </Button>
                    )
                }
            />
            <ProcurementTabs active="purchases" />
            <FilterBar url={PurchaseController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">
                        {t('procurement.search_number')}
                    </Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-48"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">
                        {t('procurement.col_status')}
                    </Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`procurement.status.${s}` as TranslationKey)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-supplier">
                        {t('procurement.col_supplier')}
                    </Label>
                    <select
                        id="f-supplier"
                        name="supplier"
                        defaultValue={filters.supplier ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name.label}
                            </option>
                        ))}
                    </select>
                </div>
            </FilterBar>
            {orders.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={PackageCheck}
                        title={t(
                            filtered
                                ? 'procurement.no_results'
                                : 'procurement.empty',
                        )}
                        description={
                            filtered ? undefined : t('procurement.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('procurement.caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('procurement.col_number')}</Th>
                                    <Th>{t('procurement.col_supplier')}</Th>
                                    <Th>{t('procurement.col_lines')}</Th>
                                    <Th>{t('procurement.col_expected')}</Th>
                                    <Th>{t('procurement.col_status')}</Th>
                                    <Th>{t('procurement.col_branch')}</Th>
                                    {showCosts && (
                                        <Th className="text-end">
                                            {t('procurement.col_gross')}
                                        </Th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((o) => (
                                    <tr key={o.id} data-test="purchase-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={PurchaseController.show.url(
                                                    { purchase: o.id },
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                {o.number}
                                            </Link>
                                        </Td>
                                        <Td>{o.supplier.name.label}</Td>
                                        <Td>
                                            {o.lines_summary.join('، ')}
                                            {o.lines >
                                                o.lines_summary.length && (
                                                <span className="text-weak">
                                                    {' '}
                                                    {t(
                                                        'procurement.more_lines',
                                                        {
                                                            n: formatNumber(
                                                                o.lines -
                                                                    o
                                                                        .lines_summary
                                                                        .length,
                                                            ),
                                                        },
                                                    )}
                                                </span>
                                            )}
                                        </Td>
                                        <Td>
                                            {o.expected_on
                                                ? formatDate(
                                                      o.expected_on,
                                                      locale,
                                                  )
                                                : '—'}
                                        </Td>
                                        <Td>
                                            <PurchaseStatusBadge
                                                status={o.status}
                                            />
                                        </Td>
                                        <Td>{o.branch.name}</Td>
                                        {showCosts && (
                                            <Td className="text-end">
                                                {o.costs_visible &&
                                                o.gross_minor !== undefined ? (
                                                    <Amount
                                                        value={
                                                            o.gross_minor / 100
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
            )}
            <Pager
                page={orders}
                url={PurchaseController.index.url()}
                query={{
                    q: filters.q,
                    status: filters.status,
                    supplier:
                        filters.supplier === null
                            ? null
                            : String(filters.supplier),
                }}
            />
        </>
    );
}

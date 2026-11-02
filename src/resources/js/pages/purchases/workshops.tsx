import { Head, Link } from '@inertiajs/react';
import { Factory } from 'lucide-react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import WorkshopBoardController from '@/actions/App/Http/Controllers/Procurement/WorkshopBoardController';
import { FilterBar } from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { StatCard } from '@/components/opsone/stat-card';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import {
    ProcurementTabs,
    PurchaseStatusBadge,
} from '@/components/procurement/shared';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { formatDate, formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { Text, WorkshopRow } from '@/types/procurement';

type Props = {
    rows: WorkshopRow[];
    summary: {
        open: number;
        late: number;
        by_workshop: {
            supplier: { id: number; name: Text };
            open: number;
            late: number;
        }[];
    };
    filters: { supplier: number | null; branch: number | null; late: boolean };
    workshops: { id: number; name: Text }[];
    branches: { id: number; name: string }[];
};

/**
 * «Orders at workshops» (ADR-0038): open workshop purchase orders with the expected date and days late, by workshop
 * and branch. Rows link to the purchase order and the customer orders it serves.
 */
export default function WorkshopsBoard({
    rows,
    summary,
    filters,
    workshops,
    branches,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const filtered = Boolean(
        filters.supplier || filters.branch || filters.late,
    );

    return (
        <>
            <Head title={t('workshops.title')} />
            <PageHeader
                title={t('workshops.title')}
                description={t('workshops.description')}
            />
            <ProcurementTabs active="workshops" />
            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label={t('workshops.stat_open')}
                    value={formatNumber(summary.open)}
                />
                <StatCard
                    label={t('workshops.stat_late')}
                    value={formatNumber(summary.late)}
                />
                {summary.by_workshop.slice(0, 2).map((w) => (
                    <StatCard
                        key={w.supplier.id}
                        label={w.supplier.name.label}
                        value={formatNumber(w.open)}
                        hint={t('workshops.late_count', {
                            n: formatNumber(w.late),
                        })}
                    />
                ))}
            </div>
            <FilterBar
                url={WorkshopBoardController.index.url()}
                reset={filtered}
            >
                <div className="grid gap-1">
                    <Label htmlFor="f-supplier">
                        {t('workshops.col_workshop')}
                    </Label>
                    <select
                        id="f-supplier"
                        name="supplier"
                        defaultValue={filters.supplier ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {workshops.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-branch">
                        {t('procurement.col_branch')}
                    </Label>
                    <select
                        id="f-branch"
                        name="branch"
                        defaultValue={filters.branch ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {branches.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-late">{t('workshops.late_only')}</Label>
                    <select
                        id="f-late"
                        name="late"
                        defaultValue={filters.late ? '1' : ''}
                        className="field-control w-40 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        <option value="1">{t('workshops.late_only')}</option>
                    </select>
                </div>
            </FilterBar>
            {rows.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Factory}
                        title={t(
                            filtered
                                ? 'workshops.no_results'
                                : 'workshops.empty',
                        )}
                        description={
                            filtered ? undefined : t('workshops.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('workshops.caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('procurement.col_number')}</Th>
                                    <Th>{t('workshops.col_workshop')}</Th>
                                    <Th>{t('workshops.col_orders')}</Th>
                                    <Th>{t('procurement.col_branch')}</Th>
                                    <Th>{t('procurement.col_expected')}</Th>
                                    <Th>{t('workshops.col_late')}</Th>
                                    <Th>{t('workshops.col_progress')}</Th>
                                    <Th>{t('procurement.col_status')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.id} data-test="workshop-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={PurchaseController.show.url(
                                                    { purchase: r.id },
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                <bdi dir="ltr">{r.number}</bdi>
                                            </Link>
                                        </Td>
                                        <Td>{r.supplier.name.label}</Td>
                                        <Td>
                                            {r.orders.length === 0
                                                ? '—'
                                                : r.orders.map((o, i) => (
                                                      <span key={o.id}>
                                                          {i > 0 && '، '}
                                                          <Link
                                                              href={OrderController.show.url(
                                                                  {
                                                                      order: o.id,
                                                                  },
                                                              )}
                                                              className="text-link hover:underline"
                                                          >
                                                              <bdi dir="ltr">
                                                                  {o.number}
                                                              </bdi>
                                                          </Link>
                                                      </span>
                                                  ))}
                                        </Td>
                                        <Td>{r.branch.name}</Td>
                                        <Td>
                                            {r.expected_on
                                                ? formatDate(
                                                      r.expected_on,
                                                      locale,
                                                  )
                                                : '—'}
                                        </Td>
                                        <Td>
                                            {r.days_late > 0 ? (
                                                <Badge variant="danger">
                                                    {t('workshops.days_late', {
                                                        n: formatNumber(
                                                            r.days_late,
                                                        ),
                                                    })}
                                                </Badge>
                                            ) : (
                                                <Badge variant="success">
                                                    {t('workshops.on_time')}
                                                </Badge>
                                            )}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {formatNumber(r.progress)}%
                                        </Td>
                                        <Td>
                                            <PurchaseStatusBadge
                                                status={r.status}
                                            />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
        </>
    );
}

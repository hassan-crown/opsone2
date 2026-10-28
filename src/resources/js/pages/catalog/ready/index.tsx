import { Head, Link } from '@inertiajs/react';
import { Plus, ShoppingBag } from 'lucide-react';
import ReadyProductController from '@/actions/App/Http/Controllers/Catalog/ReadyProductController';
import {
    CatalogTabs,
    FilterBar,
    Pager,
    StatusBadge,
} from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { CatalogTabs as Tabs, Page, Text } from '@/types/catalog';

type Row = { id: number; name: Text; status: string; variants: number };

type Props = {
    products: Page<Row>;
    filters: { q: string; status: string | null };
    tabs: Tabs;
    canCreate: boolean;
};

export default function ReadyIndex({
    products,
    filters,
    tabs,
    canCreate,
}: Props) {
    const t = useT();
    const filtered = Boolean(filters.q || filters.status);

    return (
        <>
            <Head title={t('catalog.ready.title')} />
            <PageHeader
                title={t('catalog.title')}
                description={t('catalog.ready.description')}
                actions={
                    canCreate && (
                        <Button asChild data-test="add-ready">
                            <Link href={ReadyProductController.create.url()}>
                                <Icon icon={Plus} />
                                {t('catalog.ready.add')}
                            </Link>
                        </Button>
                    )
                }
            />
            <CatalogTabs active="ready" tabs={tabs} />
            <FilterBar
                url={ReadyProductController.index.url()}
                reset={filtered}
            >
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('catalog.ready.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-56"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">{t('catalog.col_status')}</Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-36 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        <option value="draft">
                            {t('catalog.status.draft')}
                        </option>
                        <option value="published">
                            {t('catalog.status.published')}
                        </option>
                        <option value="disabled">
                            {t('catalog.status.disabled')}
                        </option>
                    </select>
                </div>
            </FilterBar>
            {products.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={ShoppingBag}
                        title={t(
                            filtered
                                ? 'catalog.no_results'
                                : 'catalog.ready.empty',
                        )}
                        description={
                            filtered ? undefined : t('catalog.ready.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('catalog.ready.caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('catalog.ready.col_product')}</Th>
                                    <Th>{t('catalog.ready.col_variants')}</Th>
                                    <Th>{t('catalog.col_status')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {products.data.map((p) => (
                                    <tr key={p.id} data-test="ready-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={ReadyProductController.edit.url(
                                                    {
                                                        ready: p.id,
                                                    },
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                {p.name.label}
                                            </Link>
                                        </Td>
                                        <Td>{p.variants}</Td>
                                        <Td>
                                            <StatusBadge status={p.status} />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <Pager
                page={products}
                url={ReadyProductController.index.url()}
                query={filters}
            />
        </>
    );
}

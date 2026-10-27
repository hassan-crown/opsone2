import { Head, Link } from '@inertiajs/react';
import { Package, Plus } from 'lucide-react';
import ProductController from '@/actions/App/Http/Controllers/Catalog/ProductController';
import {
    CatalogTabs,
    FilterBar,
    Pager,
    StatusBadge,
} from '@/components/catalog/shared';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { CatalogTabs as Tabs, Page, Text } from '@/types/catalog';

type Row = {
    id: number;
    name: Text;
    category: string | null;
    pricing_basis: string;
    reference_price_minor: number | null;
    groups: number;
    status: 'active' | 'disabled';
};

type Props = {
    products: Page<Row>;
    filters: { q: string; status: string | null; category: string | null };
    categories: string[];
    tabs: Tabs;
    canCreate: boolean;
};

export default function Products({
    products,
    filters,
    categories,
    tabs,
    canCreate,
}: Props) {
    const t = useT();
    const filtered = Boolean(filters.q || filters.status || filters.category);

    return (
        <>
            <Head title={t('catalog.products.title')} />
            <PageHeader
                title={t('catalog.title')}
                description={t('catalog.products.description')}
                actions={
                    canCreate && (
                        <Button asChild data-test="add-product">
                            <Link href={ProductController.create.url()}>
                                <Icon icon={Plus} />
                                {t('catalog.products.add')}
                            </Link>
                        </Button>
                    )
                }
            />
            <CatalogTabs active="products" tabs={tabs} />
            <FilterBar url={ProductController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('catalog.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-56"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-category">{t('catalog.category')}</Label>
                    <select
                        id="f-category"
                        name="category"
                        defaultValue={filters.category ?? ''}
                        className="field-control w-44 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {categories.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </select>
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
                        <option value="active">
                            {t('catalog.status.active')}
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
                        icon={Package}
                        title={t(
                            filtered
                                ? 'catalog.no_results'
                                : 'catalog.products.empty',
                        )}
                        description={
                            filtered
                                ? undefined
                                : t('catalog.products.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('catalog.products.caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('catalog.products.col_product')}</Th>
                                    <Th>{t('catalog.category')}</Th>
                                    <Th>{t('catalog.products.col_groups')}</Th>
                                    <Th>{t('catalog.products.col_price')}</Th>
                                    <Th>{t('catalog.col_status')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {products.data.map((p) => (
                                    <tr key={p.id} data-test="product-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={ProductController.edit.url(
                                                    {
                                                        product: p.id,
                                                    },
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                {p.name.label}
                                            </Link>
                                        </Td>
                                        <Td>{p.category ?? '—'}</Td>
                                        <Td>{p.groups}</Td>
                                        <Td>
                                            {p.reference_price_minor ===
                                            null ? (
                                                <span className="text-weak">
                                                    {t('catalog.price_unknown')}
                                                </span>
                                            ) : (
                                                <>
                                                    <Amount
                                                        value={
                                                            p.reference_price_minor /
                                                            100
                                                        }
                                                    />{' '}
                                                    <span className="text-caption text-weak">
                                                        {t(
                                                            `catalog.basis.${p.pricing_basis}` as TranslationKey,
                                                        )}
                                                    </span>
                                                </>
                                            )}
                                        </Td>
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
                url={ProductController.index.url()}
                query={filters}
            />
        </>
    );
}

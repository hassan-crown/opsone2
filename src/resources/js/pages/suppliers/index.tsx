import { Head, Link, router } from '@inertiajs/react';
import { Lock, Pencil, Plus, RotateCcw, Truck } from 'lucide-react';
import { useState } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import SupplierController from '@/actions/App/Http/Controllers/Procurement/SupplierController';
import { FilterBar, Pager } from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { RowMenu } from '@/components/org/row-menu';
import { ProcurementTabs } from '@/components/procurement/shared';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useIdempotencyKey } from '@/components/procurement/shared';
import { useT } from '@/lib/i18n';
import type { Page, SupplierRow } from '@/types/procurement';

type Props = {
    suppliers: Page<SupplierRow>;
    filters: { q: string; status: string | null };
    canManage: boolean;
    canPurchase: boolean;
};

export default function SuppliersIndex({
    suppliers,
    filters,
    canManage,
    canPurchase,
}: Props) {
    const t = useT();
    const [disabling, setDisabling] = useState<SupplierRow | null>(null);
    const { key, renew } = useIdempotencyKey();
    const filtered = Boolean(filters.q || filters.status);

    const toggle = (supplier: SupplierRow, enable: boolean) => {
        const url = enable
            ? SupplierController.enable.url({ supplier: supplier.id })
            : SupplierController.disable.url({ supplier: supplier.id });
        router.post(
            url,
            { lock_version: supplier.lock_version, idempotency_key: key },
            {
                preserveScroll: true,
                onError: renew,
                onFinish: () => setDisabling(null),
            },
        );
    };

    return (
        <>
            <Head title={t('suppliers.title')} />
            <PageHeader
                title={t('suppliers.title')}
                description={t('suppliers.description')}
                actions={
                    canManage && (
                        <Button asChild data-test="add-supplier">
                            <Link href={SupplierController.create.url()}>
                                <Icon icon={Plus} />
                                {t('suppliers.add')}
                            </Link>
                        </Button>
                    )
                }
            />
            <ProcurementTabs active="suppliers" suppliers />
            <FilterBar url={SupplierController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('suppliers.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-56"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">
                        {t('suppliers.col_status')}
                    </Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-36 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        <option value="active">
                            {t('suppliers.status.active')}
                        </option>
                        <option value="disabled">
                            {t('suppliers.status.disabled')}
                        </option>
                    </select>
                </div>
            </FilterBar>
            {suppliers.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Truck}
                        title={t(
                            filtered
                                ? 'suppliers.no_results'
                                : 'suppliers.empty',
                        )}
                        description={
                            filtered ? undefined : t('suppliers.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('suppliers.caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('suppliers.col_name')}</Th>
                                    <Th>{t('suppliers.contact_name')}</Th>
                                    <Th>{t('suppliers.phone')}</Th>
                                    <Th>{t('suppliers.vat_number')}</Th>
                                    <Th>{t('suppliers.col_status')}</Th>
                                    {canManage && (
                                        <Th className="text-end">
                                            {t('catalog.col_actions')}
                                        </Th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {suppliers.data.map((s) => (
                                    <tr key={s.id} data-test="supplier-row">
                                        <Td className="font-semibold">
                                            {s.name.label}
                                        </Td>
                                        <Td>{s.contact_name ?? '—'}</Td>
                                        <Td>
                                            {s.phone ? (
                                                <bdi dir="ltr">{s.phone}</bdi>
                                            ) : (
                                                '—'
                                            )}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {s.vat_number ?? '—'}
                                        </Td>
                                        <Td>
                                            <Badge
                                                variant={
                                                    s.status === 'active'
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {t(
                                                    s.status === 'active'
                                                        ? 'suppliers.status.active'
                                                        : 'suppliers.status.disabled',
                                                )}
                                            </Badge>
                                        </Td>
                                        {canManage && (
                                            <Td className="text-end">
                                                <RowMenu
                                                    label={t(
                                                        'catalog.row_actions',
                                                    )}
                                                    items={[
                                                        {
                                                            label: t(
                                                                'catalog.edit',
                                                            ),
                                                            icon: Pencil,
                                                            onSelect: () =>
                                                                router.get(
                                                                    SupplierController.edit.url(
                                                                        {
                                                                            supplier:
                                                                                s.id,
                                                                        },
                                                                    ),
                                                                ),
                                                        },
                                                        s.status === 'active'
                                                            ? {
                                                                  label: t(
                                                                      'catalog.disable',
                                                                  ),
                                                                  icon: Lock,
                                                                  danger: true,
                                                                  separatorBefore: true,
                                                                  onSelect:
                                                                      () =>
                                                                          setDisabling(
                                                                              s,
                                                                          ),
                                                              }
                                                            : {
                                                                  label: t(
                                                                      'catalog.enable',
                                                                  ),
                                                                  icon: RotateCcw,
                                                                  onSelect:
                                                                      () =>
                                                                          toggle(
                                                                              s,
                                                                              true,
                                                                          ),
                                                              },
                                                    ]}
                                                />
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
                page={suppliers}
                url={SupplierController.index.url()}
                query={filters}
            />
            {canPurchase && (
                <p className="mt-4 text-body">
                    <Link
                        href={PurchaseController.index.url()}
                        className="text-link hover:underline"
                    >
                        {t('suppliers.to_purchases')}
                    </Link>
                </p>
            )}

            <Dialog
                open={disabling !== null}
                onOpenChange={(value) => !value && setDisabling(null)}
            >
                <DialogContent size="sm" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('suppliers.disable_title', {
                                name: disabling?.name.label ?? '',
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('suppliers.disable_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setDisabling(null)}
                        >
                            {t('catalog.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="confirm-disable"
                            onClick={() =>
                                disabling && toggle(disabling, false)
                            }
                        >
                            {t('catalog.disable')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

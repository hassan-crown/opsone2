import { Head, Link, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import StockController from '@/actions/App/Http/Controllers/Inventory/StockController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Qty, StockTabs } from '@/components/stock/parts';
import type { LocationRef, Movement } from '@/components/stock/types';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    movements: Movement[];
    locations: LocationRef[];
    kinds: string[];
    filters: {
        location_id: number | null;
        kind: string | null;
        item_id: number | null;
    };
};

/**
 * StockMovements (FUL-04): the append-only journal of the locations whose history the viewer may see — kind,
 * signed effect, balance after, document, reference, reason, actor and time. Never edited; corrections are rows.
 */
export default function StockMovements({
    movements,
    locations,
    kinds,
    filters,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const go = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };
        router.get(
            StockController.movements.url(),
            Object.fromEntries(
                Object.entries(merged).filter(
                    ([, v]) => v !== null && v !== '',
                ),
            ),
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title={t('stock.movements.title')} />
            <PageHeader
                title={t('stock.movements.title')}
                description={t('stock.movements.description')}
            />
            <StockTabs active="movements" />
            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:w-2/3">
                <SelectField
                    label={t('stock.field.location')}
                    value={
                        filters.location_id ? String(filters.location_id) : ''
                    }
                    options={[
                        { value: '', label: t('stock.all_locations') },
                        ...locations.map((l) => ({
                            value: String(l.id),
                            label: `${l.code} — ${l.name}`,
                        })),
                    ]}
                    onChange={(e) =>
                        go({
                            location_id: e.target.value
                                ? Number(e.target.value)
                                : null,
                        })
                    }
                />
                <SelectField
                    label={t('stock.field.kind')}
                    value={filters.kind ?? ''}
                    options={[
                        { value: '', label: t('stock.all_kinds') },
                        ...kinds.map((k) => ({
                            value: k,
                            label: t(`stock.kind.${k}` as TranslationKey),
                        })),
                    ]}
                    onChange={(e) => go({ kind: e.target.value || null })}
                />
            </div>
            <Card className="p-0 md:p-0">
                {movements.length === 0 ? (
                    <EmptyState
                        icon={History}
                        title={t('stock.movements.empty')}
                    />
                ) : (
                    <TableWrap>
                        <Table
                            caption={t('stock.movements.title')}
                            data-test="movements"
                        >
                            <thead>
                                <tr>
                                    <Th>{t('stock.col.when')}</Th>
                                    <Th>{t('stock.col.kind')}</Th>
                                    <Th>{t('stock.col.item')}</Th>
                                    <Th>{t('stock.col.location')}</Th>
                                    <Th className="text-end">
                                        {t('stock.col.on_hand_change')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('stock.col.reserved_change')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('stock.col.after')}
                                    </Th>
                                    <Th>{t('stock.col.reference')}</Th>
                                    <Th>{t('stock.col.actor')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {movements.map((m) => (
                                    <tr key={m.id} data-test="movement-row">
                                        <Td className="whitespace-nowrap">
                                            {formatDateTime(
                                                m.occurred_at,
                                                locale,
                                            )}
                                        </Td>
                                        <Td>
                                            {t(
                                                `stock.kind.${m.kind}` as TranslationKey,
                                            )}
                                        </Td>
                                        <Td>{m.item.name}</Td>
                                        <Td>{m.location.code}</Td>
                                        <Td className="text-end">
                                            <Qty
                                                value={m.on_hand_delta}
                                                unit={m.item.unit}
                                            />
                                        </Td>
                                        <Td className="text-end">
                                            <Qty value={m.reserved_delta} />
                                        </Td>
                                        <Td className="text-end">
                                            <Qty value={m.on_hand_after} /> /{' '}
                                            <Qty value={m.reserved_after} />
                                        </Td>
                                        <Td>
                                            {m.document_number && (
                                                <bdi
                                                    dir="ltr"
                                                    className="block"
                                                >
                                                    {m.document_number}
                                                </bdi>
                                            )}
                                            {m.order_id && (
                                                <Link
                                                    href={OrderController.show.url(
                                                        m.order_id,
                                                    )}
                                                    className="text-caption text-link hover:underline"
                                                >
                                                    {t('stock.order_link')}
                                                </Link>
                                            )}
                                            {m.reason && (
                                                <span className="block text-caption text-weak">
                                                    {m.reason}
                                                </span>
                                            )}
                                        </Td>
                                        <Td>{m.actor ?? t('stock.system')}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>
        </>
    );
}

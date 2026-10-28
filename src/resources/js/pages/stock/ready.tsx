import { Head, Link } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Qty, StockStatus, StockTabs } from '@/components/stock/parts';
import type { Reservation } from '@/components/stock/types';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = {
    reservations: (Reservation & { order: { id: number; number: string; status: string } | null })[];
};

/**
 * ReadyStock (FUL-02, M-24): open reservations of confirmed ready orders in the viewer's locations — what is held,
 * issued and still to deliver per order. Delivery and cancellation release or issue them under the order lock.
 */
export default function ReadyStock({ reservations }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('stock.ready.title')} />
            <PageHeader title={t('stock.ready.title')} description={t('stock.ready.description')} />
            <StockTabs active="ready" />
            <Card className="p-0 md:p-0">
                {reservations.length === 0 ? (
                    <EmptyState icon={PackageCheck} title={t('stock.ready.empty')} />
                ) : (
                    <TableWrap>
                        <Table caption={t('stock.ready.title')} data-test="ready-reservations">
                            <thead>
                                <tr>
                                    <Th>{t('stock.col.reservation')}</Th>
                                    <Th>{t('stock.col.order')}</Th>
                                    <Th>{t('stock.col.item')}</Th>
                                    <Th>{t('stock.col.location')}</Th>
                                    <Th className="text-end">{t('stock.col.reserved')}</Th>
                                    <Th className="text-end">{t('stock.col.issued')}</Th>
                                    <Th className="text-end">{t('stock.col.remaining')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {reservations.map((r) => (
                                    <tr key={r.id}>
                                        <Td>
                                            <bdi dir="ltr" className="me-2">{r.number}</bdi>
                                            <StockStatus status={r.status} />
                                        </Td>
                                        <Td>
                                            {r.order && (
                                                <Link href={OrderController.show.url(r.order.id)} className="text-link hover:underline">
                                                    <bdi dir="ltr">{r.order.number}</bdi>
                                                </Link>
                                            )}
                                        </Td>
                                        <Td>{r.item.name}</Td>
                                        <Td>{r.location.code}</Td>
                                        <Td className="text-end"><Qty value={r.quantity} unit={r.item.unit} /></Td>
                                        <Td className="text-end"><Qty value={r.issued} /></Td>
                                        <Td className="text-end"><Qty value={r.remaining} strong /></Td>
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

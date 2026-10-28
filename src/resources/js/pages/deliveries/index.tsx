import { Head, Link } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import DeliveryController from '@/actions/App/Http/Controllers/Delivery/DeliveryController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { FilterBar, Pager } from '@/components/catalog/shared';
import { DeliveryStatusBadge, KindLabel } from '@/components/delivery/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    AwaitingOrder,
    DeliveryRow,
    DeliveryStatus,
    Page,
} from '@/types/delivery';

type Props = {
    awaiting: AwaitingOrder[];
    notes: Page<DeliveryRow>;
    filters: { status: DeliveryStatus | null; q: string };
};

const STATUSES: DeliveryStatus[] = ['draft', 'confirmed', 'cancelled'];

/** DelList: what is awaiting delivery (ready and custom) and the delivery notes. */
export default function DeliveriesIndex({ awaiting, notes, filters }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const filtered = Boolean(filters.q || filters.status);

    return (
        <>
            <Head title={t('delivery.title')} />
            <PageHeader
                title={t('delivery.title')}
                description={t('delivery.description')}
            />

            <Card className="mb-4 gap-0 p-0 md:p-0" data-test="awaiting">
                <h2 className="text-heading px-4 pt-4 text-strong">
                    {t('delivery.awaiting')}
                </h2>
                {awaiting.length === 0 ? (
                    <EmptyState
                        icon={PackageCheck}
                        title={t('delivery.awaiting_empty')}
                    />
                ) : (
                    <TableWrap>
                        <Table caption={t('delivery.awaiting')}>
                            <thead>
                                <tr>
                                    <Th>{t('delivery.col_order')}</Th>
                                    <Th>{t('delivery.col_customer')}</Th>
                                    <Th>{t('delivery.col_kind')}</Th>
                                    <Th>{t('delivery.col_lines')}</Th>
                                    <Th>
                                        <span className="sr-only">
                                            {t('delivery.col_actions')}
                                        </span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {awaiting.map((o) => (
                                    <tr key={o.id} data-test="awaiting-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={OrderController.show.url(
                                                    o.id,
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                {o.number}
                                            </Link>
                                        </Td>
                                        <Td>{o.customer}</Td>
                                        <Td>
                                            <KindLabel kind={o.kind} />{' '}
                                            {o.partial && (
                                                <Badge variant="brand">
                                                    {t('delivery.partial')}
                                                </Badge>
                                            )}
                                        </Td>
                                        <Td>{o.lines}</Td>
                                        <Td>
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="secondary"
                                            >
                                                <Link
                                                    href={DeliveryController.create.url(
                                                        o.id,
                                                    )}
                                                >
                                                    {t('delivery.deliver')}
                                                </Link>
                                            </Button>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>

            <h2 className="text-heading mb-2 text-strong">
                {t('delivery.notes')}
            </h2>
            <FilterBar url={DeliveryController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('delivery.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-48"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">{t('delivery.col_status')}</Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`delivery.status.${s}` as TranslationKey)}
                            </option>
                        ))}
                    </select>
                </div>
            </FilterBar>
            {notes.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={PackageCheck}
                        title={t(
                            filtered
                                ? 'delivery.no_results'
                                : 'delivery.notes_empty',
                        )}
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('delivery.notes')}>
                            <thead>
                                <tr>
                                    <Th>{t('delivery.col_number')}</Th>
                                    <Th>{t('delivery.col_order')}</Th>
                                    <Th>{t('delivery.col_customer')}</Th>
                                    <Th>{t('delivery.col_date')}</Th>
                                    <Th>{t('delivery.col_receiver')}</Th>
                                    <Th>{t('delivery.col_status')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {notes.data.map((n) => (
                                    <tr key={n.id} data-test="note-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={DeliveryController.show.url(
                                                    n.id,
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                <bdi dir="ltr">
                                                    {n.number ??
                                                        t('delivery.draft_no')}
                                                </bdi>
                                            </Link>
                                        </Td>
                                        <Td>{n.order.number}</Td>
                                        <Td>{n.customer}</Td>
                                        <Td>
                                            {n.delivered_on
                                                ? formatDate(
                                                      n.delivered_on,
                                                      locale,
                                                  )
                                                : '—'}
                                        </Td>
                                        <Td>{n.receiver_name ?? '—'}</Td>
                                        <Td>
                                            <DeliveryStatusBadge
                                                status={n.status}
                                            />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <Pager
                page={notes}
                url={DeliveryController.index.url()}
                query={{ q: filters.q, status: filters.status }}
            />
        </>
    );
}

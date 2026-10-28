import { Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck, Plus } from 'lucide-react';
import { useState } from 'react';
import StockCountController from '@/actions/App/Http/Controllers/Inventory/StockCountController';
import { Shell } from '@/components/finance/dialogs';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { ItemLines, splitKey } from '@/components/stock/item-lines';
import type { ItemLine } from '@/components/stock/item-lines';
import { StockStatus, StockTabs, useStockCall } from '@/components/stock/parts';
import type {
    LocationRef,
    StockCount,
    Trackable,
} from '@/components/stock/types';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';

type Props = {
    counts: StockCount[];
    locations: LocationRef[];
    items: Trackable[];
};

/**
 * StockCount (FUL-04): counts of a location with a reason; approval by a P-49 holder posts the differences as
 * adjustments. Nothing changes a balance before approval.
 */
export default function StockCounts({ counts, locations, items }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title={t('stock.counts.title')} />
            <PageHeader
                title={t('stock.counts.title')}
                description={t('stock.counts.description')}
                actions={
                    locations.length > 0 && (
                        <Button
                            onClick={() => setCreating(true)}
                            data-test="new-count"
                        >
                            <Icon icon={Plus} />
                            {t('stock.counts.add')}
                        </Button>
                    )
                }
            />
            <StockTabs active="counts" />
            <Card className="p-0 md:p-0">
                {counts.length === 0 ? (
                    <EmptyState
                        icon={ClipboardCheck}
                        title={t('stock.counts.empty')}
                    />
                ) : (
                    <TableWrap>
                        <Table
                            caption={t('stock.counts.title')}
                            data-test="counts"
                        >
                            <thead>
                                <tr>
                                    <Th>{t('stock.col.number')}</Th>
                                    <Th>{t('stock.col.location')}</Th>
                                    <Th>{t('stock.field.reason')}</Th>
                                    <Th>{t('stock.field.status')}</Th>
                                    <Th>{t('stock.col.when')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {counts.map((c) => (
                                    <tr key={c.id}>
                                        <Td>
                                            <Link
                                                href={StockCountController.show.url(
                                                    c.id,
                                                )}
                                                className="font-semibold text-link hover:underline"
                                            >
                                                <bdi dir="ltr">{c.number}</bdi>
                                            </Link>
                                        </Td>
                                        <Td>{c.location.code}</Td>
                                        <Td>{c.reason}</Td>
                                        <Td>
                                            <StockStatus status={c.status} />
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {formatDateTime(
                                                c.created_at,
                                                locale,
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>
            {creating && (
                <CountDialog
                    locations={locations}
                    items={items}
                    onClose={() => setCreating(false)}
                />
            )}
        </>
    );
}

function CountDialog({
    locations,
    items,
    onClose,
}: {
    locations: LocationRef[];
    items: Trackable[];
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall<{ count: { id: number } }>();
    const [location, setLocation] = useState(String(locations[0]?.id ?? ''));
    const [reason, setReason] = useState('');
    const [lines, setLines] = useState<ItemLine[]>([
        { key: '1', item: '', quantity: '' },
    ]);
    const submit = async () => {
        const done = await call.run('POST', StockCountController.store.url(), {
            location_id: Number(location),
            reason,
            lines: lines.map((l) => ({
                ...splitKey(l.item),
                counted: l.quantity,
            })),
        });
        if (done) {
            router.visit(StockCountController.show.url(done.count.id));
        }
    };

    return (
        <Shell
            size="lg"
            title={t('stock.counts.add')}
            description={t('stock.counts.form_hint')}
            error={call.error}
            onClose={onClose}
            test="count-dialog"
            footer={
                <Button disabled={call.busy} onClick={() => void submit()}>
                    {t('stock.counts.create')}
                </Button>
            }
        >
            <SelectField
                label={t('stock.field.location')}
                value={location}
                required
                options={locations.map((l) => ({
                    value: String(l.id),
                    label: `${l.code} — ${l.name}`,
                }))}
                error={call.fieldError('location_id')}
                onChange={(e) => setLocation(e.target.value)}
            />
            <TextareaField
                label={t('stock.field.reason')}
                required
                value={reason}
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
            <ItemLines
                items={items}
                lines={lines}
                onChange={setLines}
                quantityLabel={t('stock.field.counted')}
                fieldError={call.fieldError}
            />
        </Shell>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import StockCountController from '@/actions/App/Http/Controllers/Inventory/StockCountController';
import { TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { ItemLines, itemKey, splitKey } from '@/components/stock/item-lines';
import type { ItemLine } from '@/components/stock/item-lines';
import { Qty, StockStatus, useStockCall } from '@/components/stock/parts';
import type { StockCount, Trackable } from '@/components/stock/types';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = { count: StockCount; items: Trackable[] };

/**
 * One stock count: edit counted quantities while a draft, submit, then approve (posts adjustments `SA-…` for each
 * difference against the on-hand at approval time) or reject with a note.
 */
export default function StockCountPage({ count, items }: Props) {
    const t = useT();
    const call = useStockCall();
    const [note, setNote] = useState('');
    const [lines, setLines] = useState<ItemLine[]>(
        count.lines.map((l) => ({
            key: String(l.id),
            item: itemKey(l.item),
            quantity: l.counted,
        })),
    );
    const post = async (
        method: 'POST' | 'PUT',
        url: string,
        body: Record<string, unknown>,
        done: string,
    ) => {
        const ok = await call.run(method, url, {
            lock_version: count.lock_version,
            ...body,
        });
        if (ok) {
            notify('success', { title: done });
            router.reload();
        }
    };

    return (
        <>
            <Head title={count.number} />
            <PageHeader
                title={`${t('stock.counts.one')} ${count.number}`}
                description={`${count.location.code} — ${count.location.name}`}
                back={
                    <Link
                        href={StockCountController.index.url()}
                        className="text-link hover:underline"
                    >
                        {t('stock.counts.back')}
                    </Link>
                }
                actions={<StockStatus status={count.status} />}
            />
            <Card className="mb-4">
                <p className="text-body">
                    <span className="text-weak">
                        {t('stock.field.reason')}:{' '}
                    </span>
                    {count.reason}
                </p>
                {count.decision_note && (
                    <p className="mt-1 text-body">
                        <span className="text-weak">
                            {t('stock.field.note')}:{' '}
                        </span>
                        {count.decision_note}
                    </p>
                )}
            </Card>
            {call.error && (
                <Alert variant="danger" role="alert" className="mb-4">
                    <AlertBody>{call.error}</AlertBody>
                </Alert>
            )}
            {count.can.edit ? (
                <Card className="mb-4" data-test="count-edit">
                    <ItemLines
                        items={items}
                        lines={lines}
                        onChange={setLines}
                        quantityLabel={t('stock.field.counted')}
                        fieldError={call.fieldError}
                    />
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button
                            variant="secondary"
                            disabled={call.busy}
                            onClick={() =>
                                void post(
                                    'PUT',
                                    StockCountController.update.url(count.id),
                                    {
                                        lines: lines.map((l) => ({
                                            ...splitKey(l.item),
                                            counted: l.quantity,
                                        })),
                                    },
                                    t('stock.counts.saved'),
                                )
                            }
                        >
                            {t('stock.save')}
                        </Button>
                        <Button
                            disabled={call.busy}
                            onClick={() =>
                                void post(
                                    'POST',
                                    StockCountController.submit.url(count.id),
                                    {},
                                    t('stock.counts.submitted'),
                                )
                            }
                            data-test="submit-count"
                        >
                            {t('stock.counts.submit')}
                        </Button>
                    </div>
                </Card>
            ) : (
                <Card className="mb-4 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={count.number} data-test="count-lines">
                            <thead>
                                <tr>
                                    <Th>{t('stock.col.item')}</Th>
                                    <Th className="text-end">
                                        {t('stock.col.expected')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('stock.field.counted')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('stock.col.current')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('stock.col.difference')}
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {count.lines.map((l) => (
                                    <tr key={l.id}>
                                        <Td>{l.item.name}</Td>
                                        <Td className="text-end">
                                            <Qty
                                                value={l.expected}
                                                unit={l.item.unit}
                                            />
                                        </Td>
                                        <Td className="text-end">
                                            <Qty value={l.counted} strong />
                                        </Td>
                                        <Td className="text-end">
                                            <Qty value={l.current} />
                                        </Td>
                                        <Td className="text-end">
                                            <Qty value={l.difference} />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            {count.can.decide && (
                <Card data-test="count-decide">
                    <TextareaField
                        label={t('stock.field.note')}
                        hint={t('stock.counts.note_hint')}
                        value={note}
                        error={call.fieldError('note')}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                            disabled={call.busy}
                            onClick={() =>
                                void post(
                                    'POST',
                                    StockCountController.approve.url(count.id),
                                    { note: note || null },
                                    t('stock.counts.approved'),
                                )
                            }
                            data-test="approve-count"
                        >
                            {t('stock.counts.approve')}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={call.busy || note.trim() === ''}
                            onClick={() =>
                                void post(
                                    'POST',
                                    StockCountController.reject.url(count.id),
                                    { note },
                                    t('stock.counts.rejected'),
                                )
                            }
                        >
                            {t('stock.counts.reject')}
                        </Button>
                    </div>
                </Card>
            )}
        </>
    );
}

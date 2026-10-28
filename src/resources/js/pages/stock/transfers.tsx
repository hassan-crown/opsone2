import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Plus } from 'lucide-react';
import { useState } from 'react';
import StockTransferController from '@/actions/App/Http/Controllers/Inventory/StockTransferController';
import { Shell } from '@/components/finance/dialogs';
import { EmptyState } from '@/components/opsone/empty-state';
import { CheckboxField, SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { ItemLines, splitKey } from '@/components/stock/item-lines';
import type { ItemLine } from '@/components/stock/item-lines';
import { Qty, StockStatus, StockTabs, useStockCall } from '@/components/stock/parts';
import type { LocationRef, Trackable, Transfer } from '@/components/stock/types';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';

type Props = {
    transfers: Transfer[];
    locations: LocationRef[];
    items: Trackable[];
    can: { create: boolean };
};

/**
 * StockTransfer (FUL-04, M-25, P-50): linked exit and arrival. In transit the quantity is available neither at the
 * source nor at the destination; an instant transfer does both in one step.
 */
export default function StockTransfers({ transfers, locations, items, can }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [creating, setCreating] = useState(false);
    const call = useStockCall();
    const [failed, setFailed] = useState<number | null>(null);
    const act = async (transfer: Transfer, action: 'dispatch' | 'receive' | 'cancel' | 'instant') => {
        setFailed(null);
        const url = {
            dispatch: StockTransferController.dispatch.url(transfer.id),
            receive: StockTransferController.receive.url(transfer.id),
            cancel: StockTransferController.cancel.url(transfer.id),
            instant: StockTransferController.instant.url(transfer.id),
        }[action];
        const done = await call.run('POST', url, { lock_version: transfer.lock_version });
        if (done) {
            notify(t('stock.transfers.updated'), 'success');
            router.reload();
        } else {
            setFailed(transfer.id);
        }
    };

    return (
        <>
            <Head title={t('stock.transfers.title')} />
            <PageHeader
                title={t('stock.transfers.title')}
                description={t('stock.transfers.description')}
                actions={can.create && locations.length > 1 && (
                    <Button onClick={() => setCreating(true)} data-test="new-transfer">
                        <Icon icon={Plus} />
                        {t('stock.transfers.add')}
                    </Button>
                )}
            />
            <StockTabs active="transfers" />
            {transfers.length === 0 ? (
                <Card><EmptyState icon={ArrowLeftRight} title={t('stock.transfers.empty')} /></Card>
            ) : (
                <ul className="grid gap-3" data-test="transfers">
                    {transfers.map((tr) => (
                        <li key={tr.id}>
                            <Card>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <bdi dir="ltr" className="font-semibold text-strong">{tr.number}</bdi>
                                        <StockStatus status={tr.status} />
                                    </span>
                                    <span className="text-caption text-weak">
                                        {tr.from.code} → {tr.to.code} · {formatDateTime(tr.created_at, locale)}
                                    </span>
                                </div>
                                <ul className="mt-2 grid gap-1 text-body">
                                    {tr.lines.map((l) => (
                                        <li key={l.id} className="flex flex-wrap justify-between gap-2">
                                            <span>{l.item.name}</span>
                                            <Qty value={l.quantity} unit={l.item.unit} strong />
                                        </li>
                                    ))}
                                </ul>
                                {tr.note && <p className="mt-2 text-caption text-weak">{tr.note}</p>}
                                {failed === tr.id && call.error && (
                                    <Alert variant="danger" role="alert" className="mt-2"><AlertBody>{call.error}</AlertBody></Alert>
                                )}
                                {tr.can_act && (tr.status === 'draft' || tr.status === 'in_transit') && (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {tr.status === 'draft' && (
                                            <>
                                                <Button size="sm" disabled={call.busy} onClick={() => void act(tr, 'dispatch')}>{t('stock.transfers.dispatch')}</Button>
                                                <Button size="sm" variant="secondary" disabled={call.busy} onClick={() => void act(tr, 'instant')}>{t('stock.transfers.instant')}</Button>
                                                <Button size="sm" variant="ghost" disabled={call.busy} onClick={() => void act(tr, 'cancel')}>{t('stock.transfers.cancel')}</Button>
                                            </>
                                        )}
                                        {tr.status === 'in_transit' && (
                                            <Button size="sm" disabled={call.busy} onClick={() => void act(tr, 'receive')} data-test="receive-transfer">{t('stock.transfers.receive')}</Button>
                                        )}
                                    </div>
                                )}
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
            {creating && <TransferDialog locations={locations} items={items} onClose={() => setCreating(false)} />}
        </>
    );
}

function TransferDialog({ locations, items, onClose }: { locations: LocationRef[]; items: Trackable[]; onClose: () => void }) {
    const t = useT();
    const call = useStockCall();
    const [from, setFrom] = useState(String(locations[0]?.id ?? ''));
    const [to, setTo] = useState(String(locations[1]?.id ?? ''));
    const [instant, setInstant] = useState(false);
    const [note, setNote] = useState('');
    const [lines, setLines] = useState<ItemLine[]>([{ key: '1', item: '', quantity: '' }]);
    const options = locations.map((l) => ({ value: String(l.id), label: `${l.code} — ${l.name}` }));
    const submit = async () => {
        const done = await call.run('POST', StockTransferController.store.url(), {
            from_location_id: Number(from),
            to_location_id: Number(to),
            instant,
            note: note || null,
            lines: lines.map((l) => ({ ...splitKey(l.item), quantity: l.quantity })),
        });
        if (done) {
            notify(t('stock.transfers.created'), 'success');
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            size="lg"
            title={t('stock.transfers.add')}
            description={t('stock.transfers.form_hint')}
            error={call.error}
            onClose={onClose}
            test="transfer-dialog"
            footer={<Button disabled={call.busy} onClick={() => void submit()}>{t('stock.transfers.create')}</Button>}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField label={t('stock.field.from')} value={from} required options={options} error={call.fieldError('from_location_id')} onChange={(e) => setFrom(e.target.value)} />
                <SelectField label={t('stock.field.to')} value={to} required options={options} error={call.fieldError('to_location_id')} onChange={(e) => setTo(e.target.value)} />
            </div>
            <ItemLines items={items} lines={lines} onChange={setLines} quantityLabel={t('stock.field.quantity')} fieldError={call.fieldError} />
            <CheckboxField label={t('stock.transfers.instant_hint')} checked={instant} onChange={(e) => setInstant(e.target.checked)} />
            <TextareaField label={t('stock.field.note')} optional value={note} onChange={(e) => setNote(e.target.value)} />
        </Shell>
    );
}

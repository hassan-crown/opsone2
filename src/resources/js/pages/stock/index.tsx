import { Head, router } from '@inertiajs/react';
import { Boxes, Settings2, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import StockController from '@/actions/App/Http/Controllers/Inventory/StockController';
import { Shell } from '@/components/finance/dialogs';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    CheckboxField,
    SelectField,
    SwitchField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { itemKey, splitKey } from '@/components/stock/item-lines';
import { Qty, StockTabs, useStockCall } from '@/components/stock/parts';
import type {
    BalanceRow,
    StockLocation,
    Trackable,
} from '@/components/stock/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    balances: BalanceRow[];
    locations: StockLocation[];
    items: Trackable[];
    filters: { location_id: number | null; q: string; low: boolean };
    available_only: boolean;
    units: string[];
    settings: { units: string[]; low_stock_alerts: boolean };
    can: {
        adjust: boolean;
        transfer: boolean;
        history: boolean;
        settings: boolean;
    };
};

/**
 * StockBalances (FUL-01): on hand, reserved, available and in transit per item and location in the viewer's scope;
 * reorder alerts; adjustment with a reason (P-49); item unit and reorder level; enabled units (Q-43 a, P-02).
 */
export default function StockIndex({
    balances,
    locations,
    items,
    filters,
    available_only: availableOnly,
    units,
    settings,
    can,
}: Props) {
    const t = useT();
    const [dialog, setDialog] = useState<
        | { kind: 'adjust'; row: BalanceRow | null }
        | { kind: 'item'; row: BalanceRow | null }
        | { kind: 'units' }
        | null
    >(null);
    const [q, setQ] = useState(filters.q);
    const filter = (next: Partial<Props['filters']>) =>
        router.get(
            StockController.index.url(),
            {
                ...((next.location_id ?? filters.location_id)
                    ? { location_id: next.location_id ?? filters.location_id }
                    : {}),
                ...((next.q ?? filters.q) ? { q: next.q ?? filters.q } : {}),
                ...((next.low ?? filters.low) ? { low: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title={t('stock.title')} />
            <PageHeader
                title={t('stock.title')}
                description={t('stock.description')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.adjust && (
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    setDialog({ kind: 'adjust', row: null })
                                }
                                data-test="open-adjust"
                            >
                                <Icon icon={SlidersHorizontal} />
                                {t('stock.adjust.title')}
                            </Button>
                        )}
                        {can.adjust && (
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    setDialog({ kind: 'item', row: null })
                                }
                            >
                                {t('stock.item.title')}
                            </Button>
                        )}
                        {can.settings && (
                            <Button
                                variant="secondary"
                                onClick={() => setDialog({ kind: 'units' })}
                            >
                                <Icon icon={Settings2} />
                                {t('stock.settings.title')}
                            </Button>
                        )}
                    </div>
                }
            />
            <StockTabs active="balances" history={can.history} />
            <form
                className="mb-4 grid gap-3 sm:grid-cols-[14rem_1fr_auto] sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    filter({ q });
                }}
            >
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
                        router.get(
                            StockController.index.url(),
                            {
                                ...(e.target.value
                                    ? { location_id: e.target.value }
                                    : {}),
                                ...(filters.q ? { q: filters.q } : {}),
                                ...(filters.low ? { low: 1 } : {}),
                            },
                            { preserveState: true },
                        )
                    }
                />
                <TextField
                    label={t('stock.search')}
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
                <CheckboxField
                    label={t('stock.low_only')}
                    checked={filters.low}
                    onChange={(e) => filter({ low: e.target.checked })}
                />
            </form>
            <Card className="p-0 md:p-0">
                {balances.length === 0 ? (
                    <EmptyState
                        icon={Boxes}
                        title={t('stock.empty')}
                        description={t('stock.empty_body')}
                    />
                ) : (
                    <TableWrap>
                        <Table caption={t('stock.title')} data-test="balances">
                            <thead>
                                <tr>
                                    <Th>{t('stock.col.item')}</Th>
                                    <Th>{t('stock.col.location')}</Th>
                                    {!availableOnly && (
                                        <>
                                            <Th className="text-end">
                                                {t('stock.col.on_hand')}
                                            </Th>
                                            <Th className="text-end">
                                                {t('stock.col.reserved')}
                                            </Th>
                                        </>
                                    )}
                                    <Th className="text-end">
                                        {t('stock.col.available')}
                                    </Th>
                                    {!availableOnly && (
                                        <Th className="text-end">
                                            {t('stock.col.incoming')}
                                        </Th>
                                    )}
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {balances.map((b) => (
                                    <tr key={b.id} data-test="balance-row">
                                        <Td>
                                            <span className="font-semibold text-strong">
                                                {b.item.name}
                                            </span>
                                            {b.item.code && (
                                                <bdi
                                                    dir="ltr"
                                                    className="ms-2 text-caption text-weak"
                                                >
                                                    {b.item.code}
                                                </bdi>
                                            )}
                                            {b.low && (
                                                <Badge
                                                    variant="warning"
                                                    className="ms-2"
                                                >
                                                    {t('stock.low')}
                                                </Badge>
                                            )}
                                        </Td>
                                        <Td>{b.location.code}</Td>
                                        {!availableOnly && (
                                            <>
                                                <Td className="text-end">
                                                    <Hidden value={b.on_hand} />
                                                </Td>
                                                <Td className="text-end">
                                                    <Hidden
                                                        value={b.reserved}
                                                    />
                                                </Td>
                                            </>
                                        )}
                                        <Td className="text-end">
                                            <Qty
                                                value={b.available}
                                                unit={b.item.unit}
                                                strong
                                            />
                                        </Td>
                                        {!availableOnly && (
                                            <Td className="text-end">
                                                <Hidden value={b.incoming} />
                                            </Td>
                                        )}
                                        <Td className="text-end">
                                            {can.adjust && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setDialog({
                                                            kind: 'adjust',
                                                            row: b,
                                                        })
                                                    }
                                                >
                                                    {t('stock.adjust.short')}
                                                </Button>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>
            {dialog?.kind === 'adjust' && (
                <AdjustDialog
                    row={dialog.row}
                    items={items}
                    locations={locations.filter(
                        (l) => l.can?.adjust && l.status === 'active',
                    )}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'item' && (
                <ItemDialog
                    items={items}
                    units={settings.units}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'units' && (
                <UnitsDialog
                    units={units}
                    settings={settings}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

/** A quantity this viewer may not see for this location (available only) shows as a dash, never as 0. */
function Hidden({ value }: { value: string | null }) {
    return value === null ? (
        <span className="text-weak">—</span>
    ) : (
        <Qty value={value} />
    );
}

function AdjustDialog({
    row,
    items,
    locations,
    onClose,
}: {
    row: BalanceRow | null;
    items: Trackable[];
    locations: StockLocation[];
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall<{ movement: { document_number: string } }>();
    const [form, setForm] = useState({
        location: row
            ? String(row.location.id)
            : locations[0]
              ? String(locations[0].id)
              : '',
        item: row ? itemKey(row.item) : '',
        direction: 'out',
        quantity: '',
        reason: '',
    });
    const submit = async () => {
        const done = await call.run('POST', StockController.adjust.url(), {
            location_id: Number(form.location),
            ...splitKey(form.item),
            direction: form.direction,
            quantity: form.quantity,
            reason: form.reason,
        });
        if (done) {
            notify('success', {
                title: t('stock.adjust.done', {
                    number: done.movement.document_number,
                }),
            });
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t('stock.adjust.title')}
            description={t('stock.adjust.hint')}
            error={call.error}
            onClose={onClose}
            test="adjust-dialog"
            footer={
                <Button disabled={call.busy} onClick={() => void submit()}>
                    {t('stock.adjust.submit')}
                </Button>
            }
        >
            <SelectField
                label={t('stock.field.location')}
                value={form.location}
                required
                options={locations.map((l) => ({
                    value: String(l.id),
                    label: `${l.code} — ${l.name}`,
                }))}
                error={call.fieldError('location_id')}
                onChange={(e) => setForm({ ...form, location: e.target.value })}
            />
            <SelectField
                label={t('stock.field.item')}
                value={form.item}
                required
                placeholder={t('stock.choose_item')}
                options={items.map((i) => ({
                    value: itemKey(i),
                    label: i.code ? `${i.name} (${i.code})` : i.name,
                }))}
                error={call.fieldError('item_id') ?? call.fieldError('unit')}
                onChange={(e) => setForm({ ...form, item: e.target.value })}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    label={t('stock.field.direction')}
                    value={form.direction}
                    required
                    options={['in', 'out'].map((d) => ({
                        value: d,
                        label: t(`stock.direction.${d}` as TranslationKey),
                    }))}
                    onChange={(e) =>
                        setForm({ ...form, direction: e.target.value })
                    }
                />
                <TextField
                    label={t('stock.field.quantity')}
                    dir="ltr"
                    inputMode="decimal"
                    required
                    value={form.quantity}
                    error={call.fieldError('quantity')}
                    onChange={(e) =>
                        setForm({ ...form, quantity: e.target.value })
                    }
                />
            </div>
            <TextareaField
                label={t('stock.field.reason')}
                required
                value={form.reason}
                error={call.fieldError('reason')}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
            />
        </Shell>
    );
}

function ItemDialog({
    items,
    units,
    onClose,
}: {
    items: Trackable[];
    units: string[];
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [form, setForm] = useState({ item: '', unit: '', level: '' });
    const chosen = items.find((i) => itemKey(i) === form.item);
    const submit = async () => {
        const done = await call.run('PUT', StockController.updateItem.url(), {
            ...splitKey(form.item),
            unit: form.unit || null,
            reorder_level: form.level || null,
        });
        if (done) {
            notify('success', { title: t('stock.item.saved') });
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t('stock.item.title')}
            description={t('stock.item.hint')}
            error={call.error}
            onClose={onClose}
            test="item-dialog"
            footer={
                <Button
                    disabled={call.busy || form.item === ''}
                    onClick={() => void submit()}
                >
                    {t('stock.save')}
                </Button>
            }
        >
            <SelectField
                label={t('stock.field.item')}
                value={form.item}
                required
                placeholder={t('stock.choose_item')}
                options={items.map((i) => ({
                    value: itemKey(i),
                    label: i.name,
                }))}
                onChange={(e) =>
                    setForm({
                        ...form,
                        item: e.target.value,
                        unit:
                            items.find((i) => itemKey(i) === e.target.value)
                                ?.unit ?? '',
                    })
                }
            />
            {chosen?.type === 'material' && (
                <SelectField
                    label={t('stock.field.unit')}
                    hint={t('stock.item.unit_hint')}
                    value={form.unit}
                    options={[
                        { value: '', label: '—' },
                        ...units.map((u) => ({
                            value: u,
                            label: t(`stock.unit.${u}` as TranslationKey),
                        })),
                    ]}
                    error={call.fieldError('unit')}
                    onChange={(e) => setForm({ ...form, unit: e.target.value })}
                />
            )}
            <TextField
                label={t('stock.field.reorder_level')}
                hint={t('stock.item.reorder_hint')}
                dir="ltr"
                inputMode="decimal"
                optional
                value={form.level}
                error={call.fieldError('reorder_level')}
                onChange={(e) => setForm({ ...form, level: e.target.value })}
            />
        </Shell>
    );
}

function UnitsDialog({
    units,
    settings,
    onClose,
}: {
    units: string[];
    settings: { units: string[]; low_stock_alerts: boolean };
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [enabled, setEnabled] = useState<string[]>(settings.units);
    const [alerts, setAlerts] = useState(settings.low_stock_alerts);
    const submit = async () => {
        const done = await call.run(
            'PUT',
            StockController.updateSettings.url(),
            { units: enabled, low_stock_alerts: alerts },
        );
        if (done) {
            notify('success', { title: t('stock.settings.saved') });
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t('stock.settings.title')}
            description={t('stock.settings.hint')}
            error={call.error}
            onClose={onClose}
            test="units-dialog"
            footer={
                <Button disabled={call.busy} onClick={() => void submit()}>
                    {t('stock.save')}
                </Button>
            }
        >
            <fieldset className="grid gap-1 sm:grid-cols-2">
                <legend className="mb-2 text-body font-semibold text-strong">
                    {t('stock.settings.units')}
                </legend>
                {units.map((u) => (
                    <CheckboxField
                        key={u}
                        label={t(`stock.unit.${u}` as TranslationKey)}
                        checked={u === 'piece' || enabled.includes(u)}
                        disabled={u === 'piece'}
                        onChange={(e) =>
                            setEnabled(
                                e.target.checked
                                    ? [...enabled, u]
                                    : enabled.filter((x) => x !== u),
                            )
                        }
                    />
                ))}
            </fieldset>
            <SwitchField
                label={t('stock.settings.alerts')}
                checked={alerts}
                onCheckedChange={setAlerts}
            />
        </Shell>
    );
}

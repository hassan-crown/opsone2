import { Head, Link, router } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { useState } from 'react';
import OrderStockController from '@/actions/App/Http/Controllers/Inventory/OrderStockController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { Shell } from '@/components/finance/dialogs';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import {
    newKey,
    Qty,
    StockStatus,
    useStockCall,
} from '@/components/stock/parts';
import type {
    Reservation,
    StockItemRef,
    StockLocation,
} from '@/components/stock/types';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Material = {
    id: number;
    name: string;
    unit: string | null;
    available: Record<string, string>;
};
type Props = {
    order: { id: number; number: string; status: string; lock_version: number };
    reservations: Reservation[];
    issues: {
        id: number;
        document_number: string | null;
        item: StockItemRef;
        quantity: string;
        location_id: number;
        occurred_at: string;
    }[];
    locations: StockLocation[];
    materials: Material[];
    can: { manage: boolean };
};

type Mode =
    | { kind: 'reserve' }
    | { kind: 'issue'; reservation: Reservation | null }
    | { kind: 'release'; reservation: Reservation };

/**
 * CustomMaterials (FUL-03, P-51 — the screen missing from the prototypes): reserve or issue a tracked material on a
 * made-to-order order by hand, from a location the user may use. No consumption is computed from dimensions;
 * descriptive materials never appear here.
 */
export default function OrderMaterials({
    order,
    reservations,
    issues,
    locations,
    materials,
    can,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [mode, setMode] = useState<Mode | null>(null);

    return (
        <>
            <Head title={`${t('stock.materials.title')} ${order.number}`} />
            <PageHeader
                title={t('stock.materials.title')}
                description={t('stock.materials.description')}
                back={
                    <Link
                        href={OrderController.show.url(order.id)}
                        className="text-link hover:underline"
                    >
                        <bdi dir="ltr">{order.number}</bdi>
                    </Link>
                }
                actions={
                    can.manage &&
                    materials.length > 0 &&
                    locations.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() => setMode({ kind: 'reserve' })}
                                data-test="reserve-material"
                            >
                                {t('stock.materials.reserve')}
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    setMode({
                                        kind: 'issue',
                                        reservation: null,
                                    })
                                }
                                data-test="issue-material"
                            >
                                {t('stock.materials.issue')}
                            </Button>
                        </div>
                    )
                }
            />
            {materials.length === 0 && (
                <Alert variant="info" className="mb-4">
                    <AlertBody>{t('stock.materials.none_tracked')}</AlertBody>
                </Alert>
            )}
            <section className="mb-6">
                <h2 className="text-heading mb-2 text-strong">
                    {t('stock.materials.reservations')}
                </h2>
                <Card className="p-0 md:p-0">
                    {reservations.length === 0 ? (
                        <EmptyState
                            compact
                            icon={Boxes}
                            title={t('stock.materials.no_reservations')}
                        />
                    ) : (
                        <TableWrap>
                            <Table
                                caption={t('stock.materials.reservations')}
                                data-test="material-reservations"
                            >
                                <thead>
                                    <tr>
                                        <Th>{t('stock.col.reservation')}</Th>
                                        <Th>{t('stock.col.item')}</Th>
                                        <Th>{t('stock.col.location')}</Th>
                                        <Th className="text-end">
                                            {t('stock.col.reserved')}
                                        </Th>
                                        <Th className="text-end">
                                            {t('stock.col.issued')}
                                        </Th>
                                        <Th className="text-end">
                                            {t('stock.col.remaining')}
                                        </Th>
                                        <Th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {reservations.map((r) => (
                                        <tr key={r.id}>
                                            <Td>
                                                <bdi dir="ltr" className="me-2">
                                                    {r.number}
                                                </bdi>
                                                <StockStatus
                                                    status={r.status}
                                                />
                                            </Td>
                                            <Td>{r.item.name}</Td>
                                            <Td>{r.location.code}</Td>
                                            <Td className="text-end">
                                                <Qty
                                                    value={r.quantity}
                                                    unit={r.item.unit}
                                                />
                                            </Td>
                                            <Td className="text-end">
                                                <Qty value={r.issued} />
                                            </Td>
                                            <Td className="text-end">
                                                <Qty
                                                    value={r.remaining}
                                                    strong
                                                />
                                            </Td>
                                            <Td className="text-end">
                                                {can.manage &&
                                                    (r.status === 'active' ||
                                                        r.status ===
                                                            'partially_issued') && (
                                                        <span className="inline-flex gap-1">
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setMode({
                                                                        kind: 'issue',
                                                                        reservation:
                                                                            r,
                                                                    })
                                                                }
                                                            >
                                                                {t(
                                                                    'stock.materials.issue',
                                                                )}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setMode({
                                                                        kind: 'release',
                                                                        reservation:
                                                                            r,
                                                                    })
                                                                }
                                                            >
                                                                {t(
                                                                    'stock.materials.release',
                                                                )}
                                                            </Button>
                                                        </span>
                                                    )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                    )}
                </Card>
            </section>
            <section>
                <h2 className="text-heading mb-2 text-strong">
                    {t('stock.materials.issues')}
                </h2>
                <Card className="p-0 md:p-0">
                    {issues.length === 0 ? (
                        <EmptyState
                            compact
                            icon={Boxes}
                            title={t('stock.materials.no_issues')}
                        />
                    ) : (
                        <TableWrap>
                            <Table caption={t('stock.materials.issues')}>
                                <thead>
                                    <tr>
                                        <Th>{t('stock.col.number')}</Th>
                                        <Th>{t('stock.col.item')}</Th>
                                        <Th className="text-end">
                                            {t('stock.field.quantity')}
                                        </Th>
                                        <Th>{t('stock.col.when')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {issues.map((m) => (
                                        <tr key={m.id}>
                                            <Td>
                                                <bdi dir="ltr">
                                                    {m.document_number}
                                                </bdi>
                                            </Td>
                                            <Td>{m.item.name}</Td>
                                            <Td className="text-end">
                                                <Qty
                                                    value={m.quantity}
                                                    unit={m.item.unit}
                                                />
                                            </Td>
                                            <Td>
                                                {formatDateTime(
                                                    m.occurred_at,
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
            </section>
            {mode && mode.kind !== 'release' && (
                <MaterialDialog
                    order={order}
                    mode={mode}
                    materials={materials}
                    locations={locations}
                    onClose={() => setMode(null)}
                />
            )}
            {mode?.kind === 'release' && (
                <ReleaseDialog
                    order={order}
                    reservation={mode.reservation}
                    onClose={() => setMode(null)}
                />
            )}
        </>
    );
}

function MaterialDialog({
    order,
    mode,
    materials,
    locations,
    onClose,
}: {
    order: Props['order'];
    mode: Exclude<Mode, { kind: 'release' }>;
    materials: Material[];
    locations: StockLocation[];
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [key] = useState(newKey);
    const fixed = mode.kind === 'issue' ? mode.reservation : null;
    const [form, setForm] = useState({
        material: fixed
            ? String(fixed.item.item_id)
            : String(materials[0]?.id ?? ''),
        location: fixed
            ? String(fixed.location.id)
            : String(locations[0]?.id ?? ''),
        quantity: fixed ? fixed.remaining : '',
    });
    const material = materials.find((m) => String(m.id) === form.material);
    const submit = async () => {
        const url =
            mode.kind === 'reserve'
                ? OrderStockController.reserve.url(order.id)
                : OrderStockController.issue.url(order.id);
        const done = await call.run('POST', url, {
            location_id: Number(form.location),
            material_id: Number(form.material),
            quantity: form.quantity,
            reservation_id: fixed?.id ?? null,
            idempotency_key: key,
        });
        if (done) {
            notify('success', {
                title: t(
                    mode.kind === 'reserve'
                        ? 'stock.materials.reserved'
                        : 'stock.materials.issued',
                ),
            });
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t(
                mode.kind === 'reserve'
                    ? 'stock.materials.reserve'
                    : 'stock.materials.issue',
            )}
            description={t(
                mode.kind === 'reserve'
                    ? 'stock.materials.reserve_hint'
                    : 'stock.materials.issue_hint',
            )}
            error={call.error}
            onClose={onClose}
            test="material-dialog"
            footer={
                <Button disabled={call.busy} onClick={() => void submit()}>
                    {t('stock.save')}
                </Button>
            }
        >
            <SelectField
                label={t('stock.field.material')}
                value={form.material}
                required
                disabled={fixed !== null}
                options={materials.map((m) => ({
                    value: String(m.id),
                    label: m.name,
                }))}
                error={
                    call.fieldError('material_id') ?? call.fieldError('unit')
                }
                onChange={(e) => setForm({ ...form, material: e.target.value })}
            />
            <SelectField
                label={t('stock.field.location')}
                value={form.location}
                required
                disabled={fixed !== null}
                options={locations.map((l) => ({
                    value: String(l.id),
                    label: `${l.code} — ${l.name}`,
                }))}
                error={call.fieldError('location_id')}
                onChange={(e) => setForm({ ...form, location: e.target.value })}
            />
            {material && (
                <p
                    className="text-caption text-weak"
                    data-test="material-available"
                >
                    {t('stock.col.available')}:{' '}
                    <Qty
                        value={material.available[form.location] ?? '0'}
                        unit={material.unit}
                    />
                </p>
            )}
            <TextField
                label={
                    material?.unit
                        ? `${t('stock.field.quantity')} (${t(`stock.unit.${material.unit}` as TranslationKey)})`
                        : t('stock.field.quantity')
                }
                dir="ltr"
                inputMode="decimal"
                required
                value={form.quantity}
                error={call.fieldError('quantity')}
                onChange={(e) => setForm({ ...form, quantity: e.target.value })}
            />
        </Shell>
    );
}

function ReleaseDialog({
    order,
    reservation,
    onClose,
}: {
    order: Props['order'];
    reservation: Reservation;
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [reason, setReason] = useState('');
    const submit = async () => {
        const done = await call.run(
            'POST',
            OrderStockController.release.url({
                order: order.id,
                reservation: reservation.id,
            }),
            { reason },
        );
        if (done) {
            notify('success', { title: t('stock.materials.released') });
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t('stock.materials.release')}
            description={t('stock.materials.release_hint', {
                n: reservation.remaining,
            })}
            error={call.error}
            onClose={onClose}
            test="release-dialog"
            footer={
                <Button
                    disabled={call.busy || reason.trim() === ''}
                    onClick={() => void submit()}
                >
                    {t('stock.materials.release')}
                </Button>
            }
        >
            <TextareaField
                label={t('stock.field.reason')}
                required
                value={reason}
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
        </Shell>
    );
}

import { Link, router } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { useState } from 'react';
import OrderStockController from '@/actions/App/Http/Controllers/Inventory/OrderStockController';
import { Shell } from '@/components/finance/dialogs';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { Qty, StockStatus, useStockCall } from './parts';
import type { OrderStock } from './types';

/**
 * Stock side of an order (Ready*: FUL-02, M-05-T01/T09; custom: link to the P-51 materials screen). Availability
 * shows before confirmation; reservations after it. The server re-checks stock under row locks on every action.
 */
export function OrderStockCard({
    orderId,
    lockVersion,
    status,
    stock,
}: {
    orderId: number;
    lockVersion: number;
    status: string;
    stock: OrderStock;
}) {
    const t = useT();
    const [dialog, setDialog] = useState<'confirm' | 'cancel' | null>(null);

    if (stock.kind === 'custom') {
        return (
            <Card data-test="order-stock">
                <h2 className="text-heading mb-2 flex items-center gap-2 text-strong">
                    <Icon icon={Boxes} />
                    {t('stock.order.materials')}
                </h2>
                <p className="mb-3 text-caption text-weak">
                    {t('stock.order.materials_hint', {
                        n: stock.open_reservations,
                    })}
                </p>
                {stock.can.materials && (
                    <Button asChild variant="secondary" size="sm">
                        <Link href={OrderStockController.materials.url(orderId)}>
                            {t('stock.order.open_materials')}
                        </Link>
                    </Button>
                )}
            </Card>
        );
    }

    const short = stock.lines.some((l) => l.short !== '0');

    return (
        <Card data-test="order-stock">
            <h2 className="text-heading mb-2 flex items-center gap-2 text-strong">
                <Icon icon={Boxes} />
                {t('stock.order.title')}
            </h2>
            <ul className="grid gap-2">
                {stock.lines.map((line) => (
                    <li
                        key={line.line_id}
                        className="rounded-md border border-stroke-weak p-3"
                        data-test="stock-line"
                    >
                        <div className="flex flex-wrap justify-between gap-2 text-body">
                            <span className="text-weak">
                                {t('stock.order.required')}
                            </span>
                            <Qty value={line.quantity} unit="piece" strong />
                        </div>
                        {status === 'draft' ? (
                            <>
                                <div className="flex flex-wrap justify-between gap-2 text-body">
                                    <span className="text-weak">
                                        {t('stock.col.available')}
                                    </span>
                                    <Qty value={line.available} unit="piece" />
                                </div>
                                {line.short !== '0' && (
                                    <p className="mt-1 text-caption text-danger-text">
                                        {t('stock.order.short', {
                                            n: line.short,
                                        })}
                                    </p>
                                )}
                            </>
                        ) : (
                            line.reservations.map((r) => (
                                <div
                                    key={r.id}
                                    className="mt-1 flex flex-wrap items-center justify-between gap-2 text-caption"
                                >
                                    <span className="flex items-center gap-2">
                                        <bdi dir="ltr">{r.number}</bdi>
                                        <StockStatus status={r.status} />
                                    </span>
                                    <span className="text-weak">
                                        {r.location.code} ·{' '}
                                        {t('stock.order.held', {
                                            n: r.remaining,
                                        })}{' '}
                                        ·{' '}
                                        {t('stock.order.issued', {
                                            n: r.issued,
                                        })}
                                    </span>
                                </div>
                            ))
                        )}
                    </li>
                ))}
            </ul>
            {(stock.can.confirm || stock.can.cancel) && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {stock.can.confirm && (
                        <Button
                            size="sm"
                            onClick={() => setDialog('confirm')}
                            data-test="confirm-ready"
                        >
                            {t('stock.order.confirm')}
                        </Button>
                    )}
                    {stock.can.cancel && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => setDialog('cancel')}
                            data-test="cancel-ready"
                        >
                            {t('stock.order.cancel')}
                        </Button>
                    )}
                </div>
            )}
            {dialog === 'confirm' && (
                <ConfirmDialog
                    orderId={orderId}
                    lockVersion={lockVersion}
                    locations={stock.locations}
                    short={short}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'cancel' && (
                <CancelDialog
                    orderId={orderId}
                    lockVersion={lockVersion}
                    onClose={() => setDialog(null)}
                />
            )}
        </Card>
    );
}

function ConfirmDialog({
    orderId,
    lockVersion,
    locations,
    short,
    onClose,
}: {
    orderId: number;
    lockVersion: number;
    locations: { id: number; code: string; name: string }[];
    short: boolean;
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [location, setLocation] = useState(
        locations[0] ? String(locations[0].id) : '',
    );
    const submit = async () => {
        const done = await call.run(
            'POST',
            OrderStockController.confirm.url(orderId),
            { location_id: location ? Number(location) : null, lock_version: lockVersion },
        );
        if (done) {
            notify(t('stock.order.confirmed'), 'success');
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t('stock.order.confirm')}
            description={t('stock.order.confirm_hint')}
            error={call.error}
            onClose={onClose}
            test="confirm-ready-dialog"
            footer={
                <Button disabled={call.busy || location === ''} onClick={() => void submit()}>
                    {t('stock.order.confirm')}
                </Button>
            }
        >
            {locations.length === 0 ? (
                <Alert variant="warning">
                    <AlertBody>{t('stock.order.no_location')}</AlertBody>
                </Alert>
            ) : (
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
            )}
            {short && (
                <Alert variant="warning">
                    <AlertBody>{t('stock.order.short_warning')}</AlertBody>
                </Alert>
            )}
        </Shell>
    );
}

function CancelDialog({
    orderId,
    lockVersion,
    onClose,
}: {
    orderId: number;
    lockVersion: number;
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [reason, setReason] = useState('');
    const submit = async () => {
        const done = await call.run(
            'POST',
            OrderStockController.cancelRemainder.url(orderId),
            { reason, lock_version: lockVersion },
        );
        if (done) {
            notify(t('stock.order.cancelled'), 'success');
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={t('stock.order.cancel')}
            description={t('stock.order.cancel_hint')}
            error={call.error}
            onClose={onClose}
            test="cancel-ready-dialog"
            footer={
                <Button
                    variant="destructive"
                    disabled={call.busy || reason.trim() === ''}
                    onClick={() => void submit()}
                >
                    {t('stock.order.cancel')}
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

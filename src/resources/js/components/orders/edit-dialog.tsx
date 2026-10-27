import { router } from '@inertiajs/react';
import { useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import {
    CheckboxField,
    SelectField,
    TextareaField,
} from '@/components/opsone/field';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { ConflictAlert } from './conflict';
import type { Option, OrderDetail } from './types';

/** Edits the need, flags, invoice type and responsible person with the order's lock_version (409 on a stale save). */
export function EditOrderDialog({
    open,
    onOpenChange,
    order,
    members,
    invoiceTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: OrderDetail;
    members: Option[];
    invoiceTypes: string[];
}) {
    const t = useT();
    const custom = order.kind === 'custom';
    const [need, setNeed] = useState(order.need_summary ?? '');
    const [measurement, setMeasurement] = useState(order.needs_measurement);
    const [design, setDesign] = useState(order.needs_design);
    const [invoice, setInvoice] = useState(order.invoice_type ?? '');
    const [assignee, setAssignee] = useState(String(order.assignee?.id ?? ''));
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [stale, setStale] = useState(false);

    const save = async () => {
        if (busy) {
            return;
        }
        setBusy(true);
        setErrors({});
        setMessage(null);
        const result = await api('PUT', OrderController.update.url(order.id), {
            lock_version: order.lock_version,
            ...(custom
                ? {
                      need_summary: need,
                      needs_measurement: measurement,
                      needs_design: design,
                  }
                : {}),
            invoice_type: invoice || null,
            ...(assignee === '' ? {} : { assignee_id: Number(assignee) }),
        });
        setBusy(false);

        if (result.ok) {
            notify('success', { title: t('orders.edit.saved') });
            onOpenChange(false);
            router.reload();

            return;
        }
        if (result.stale) {
            setStale(true);

            return;
        }
        setErrors(result.errors);
        if (Object.keys(result.errors).length === 0) {
            setMessage(result.message ?? t('orders.edit.failed'));
        }
    };

    const first = (k: string) => errors[k]?.[0];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('orders.edit.title')}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('orders.edit.title')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4" data-test="edit-order-form">
                    {stale && <ConflictAlert />}
                    {message && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{message}</AlertBody>
                        </Alert>
                    )}
                    {custom && (
                        <>
                            <TextareaField
                                label={t('orders.field.need_summary')}
                                value={need}
                                onChange={(e) => setNeed(e.target.value)}
                                maxLength={2000}
                                error={first('need_summary')}
                                required
                            />
                            <CheckboxField
                                label={t('orders.field.needs_measurement')}
                                checked={measurement}
                                onChange={(e) =>
                                    setMeasurement(e.target.checked)
                                }
                            />
                            <CheckboxField
                                label={t('orders.field.needs_design')}
                                checked={design}
                                onChange={(e) => setDesign(e.target.checked)}
                            />
                        </>
                    )}
                    <SelectField
                        label={t('orders.field.invoice_type')}
                        value={invoice}
                        onChange={(e) => setInvoice(e.target.value)}
                        options={[
                            { value: '', label: t('orders.invoice.none') },
                            ...invoiceTypes.map((v) => ({
                                value: v,
                                label: t(
                                    `orders.invoice.${v}` as TranslationKey,
                                ),
                            })),
                        ]}
                        error={first('invoice_type')}
                        optional
                    />
                    <SelectField
                        label={t('orders.field.assignee')}
                        value={assignee}
                        onChange={(e) => setAssignee(e.target.value)}
                        options={[
                            ...(order.assignee
                                ? []
                                : [
                                      {
                                          value: '',
                                          label: t('orders.unassigned'),
                                      },
                                  ]),
                            ...members.map((m) => ({
                                value: String(m.id),
                                label: m.name,
                            })),
                        ]}
                        error={first('assignee_id')}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('orders.create.cancel')}
                        </Button>
                        <Button
                            type="button"
                            loading={busy}
                            onClick={() => void save()}
                            data-test="edit-order-save"
                        >
                            {t('orders.edit.save')}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}

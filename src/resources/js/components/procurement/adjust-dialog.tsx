import { useForm } from '@inertiajs/react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import { TextField, TextareaField } from '@/components/opsone/field';
import { milliToInput, parseQty } from '@/components/procurement/quantity';
import {
    FormErrors,
    Qty,
    unitLabel,
    useIdempotencyKey,
} from '@/components/procurement/shared';
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
import { useT } from '@/lib/i18n';
import type { PurchaseDetail } from '@/types/procurement';

/**
 * Change the ordered quantity of open lines: the explicit agreement an over-supply needs. It cannot go below what
 * was already accepted (the server enforces it; the hint says so first).
 */
export function AdjustDialog({
    purchase,
    open,
    onOpenChange,
}: {
    purchase: PurchaseDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const lines = purchase.lines;
    const form = useForm({
        quantities: Object.fromEntries(
            lines.map((l) => [String(l.id), milliToInput(l.quantity_milli)]),
        ) as Record<string, string>,
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const changed = lines.filter((l) => {
        const parsed = parseQty(form.data.quantities[String(l.id)] ?? '');

        return parsed !== l.quantity_milli;
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            reason: data.reason.trim(),
            changes: changed.map((l) => ({
                line_id: l.id,
                quantity: (data.quantities[String(l.id)] ?? '').trim(),
            })),
            idempotency_key: key,
            lock_version: purchase.lock_version,
        }));
        form.post(PurchaseController.adjust.url({ purchase: purchase.id }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: renew,
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="lg" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('procurement.adjust_title')}</DialogTitle>
                    <DialogDescription>
                        {t('procurement.adjust_desc')}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="grid gap-4"
                    data-test="adjust-form"
                >
                    <FormErrors errors={errors} />
                    <Alert variant="info">
                        <AlertBody>{t('procurement.adjust_hint')}</AlertBody>
                    </Alert>
                    <ul className="grid gap-3">
                        {lines.map((l, i) => {
                            const index = changed.findIndex(
                                (c) => c.id === l.id,
                            );

                            return (
                                <li
                                    key={l.id}
                                    className="grid gap-2 md:grid-cols-[1fr_11rem] md:items-start"
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold text-strong">
                                            {l.name.label}
                                        </p>
                                        <p className="text-caption text-weak">
                                            {t('procurement.adjust_current', {
                                                n: i + 1,
                                            })}{' '}
                                            <Qty
                                                milli={l.quantity_milli}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t('procurement.col_accepted')}{' '}
                                            <Qty
                                                milli={l.accepted_milli}
                                                unit={l.unit}
                                            />
                                        </p>
                                    </div>
                                    <TextField
                                        label={`${t('procurement.adjust_new')} (${unitLabel(l.unit, t)})`}
                                        inputMode="decimal"
                                        dir="ltr"
                                        value={
                                            form.data.quantities[String(l.id)]
                                        }
                                        onChange={(e) =>
                                            form.setData('quantities', {
                                                ...form.data.quantities,
                                                [String(l.id)]: e.target.value,
                                            })
                                        }
                                        error={
                                            index >= 0
                                                ? errors[
                                                      `changes.${index}.quantity`
                                                  ]
                                                : undefined
                                        }
                                    />
                                </li>
                            );
                        })}
                    </ul>
                    <TextareaField
                        label={t('procurement.reason')}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        required
                        error={errors.reason}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('catalog.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            loading={form.processing}
                            disabled={form.processing || changed.length === 0}
                            data-test="confirm-adjust"
                        >
                            {t('procurement.adjust_save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

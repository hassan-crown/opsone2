import { useForm } from '@inertiajs/react';
import DeliveryController from '@/actions/App/Http/Controllers/Delivery/DeliveryController';
import { QtyText } from '@/components/delivery/shared';
import {
    RadioGroup,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { useIdempotencyKey } from '@/components/procurement/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { DeliveryDetail } from '@/types/delivery';

/**
 * M-14-T03: correct the delivered quantity of a confirmed note with a reason. For ready goods the person chooses where
 * the quantity goes: back to the general stock or back into this customer's reservation.
 */
export function DeliveryCorrectionCard({ note }: { note: DeliveryDetail }) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();
    const form = useForm({
        quantities: Object.fromEntries(
            note.lines.map((l) => [String(l.id), '']),
        ) as Record<string, string>,
        destination: '',
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lineName = (id: number) =>
        note.lines.find((l) => l.id === id)?.name ?? String(id);
    const lines = Object.entries(form.data.quantities)
        .filter(([, q]) => q.trim() !== '' && Number(q) > 0)
        .map(([id, quantity]) => ({ line_id: Number(id), quantity }));
    const ready =
        lines.length > 0 &&
        form.data.reason.trim().length >= 3 &&
        (!note.can.correct_needs_destination || form.data.destination !== '');

    const submit = () => {
        form.transform((data) => ({
            lines,
            destination: data.destination === '' ? null : data.destination,
            reason: data.reason,
            idempotency_key: key,
            lock_version: note.lock_version,
        }));
        form.post(DeliveryController.correct.url(note.id), {
            preserveScroll: true,
            onSuccess: () => {
                renew();
                form.reset();
            },
            onError: renew,
        });
    };

    return (
        <Card className="gap-3" data-test="delivery-corrections">
            <h2 className="text-heading text-strong">
                {t('delivery.correction.title')}
            </h2>
            {note.corrections.length > 0 && (
                <ul className="grid gap-2" data-test="correction-history">
                    {note.corrections.map((c) => (
                        <li key={c.id} className="text-caption">
                            <span className="text-weak">
                                {formatDate(c.at, locale)} · {c.by ?? '—'}
                                {c.destination &&
                                    ` · ${t(`delivery.correction.to_${c.destination}`)}`}
                            </span>
                            <p className="text-strong">{c.reason}</p>
                            <ul className="ms-4 list-disc text-weak">
                                {c.lines.map((l) => (
                                    <li key={l.line_id}>
                                        {lineName(l.line_id)}: −{l.quantity}
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
            {note.can.correct && (
                <div className="grid gap-3" data-test="correction-form">
                    <p className="text-caption text-weak">
                        {t('delivery.correction.hint')}
                    </p>
                    {note.lines.map((l) => (
                        <div
                            key={l.id}
                            className="grid items-end gap-2 md:grid-cols-2"
                        >
                            <div className="text-body">
                                {l.name}{' '}
                                <span className="text-caption text-weak">
                                    ({t('delivery.correction.net')}:{' '}
                                    <QtyText value={l.net} unit={l.unit} />)
                                </span>
                            </div>
                            <TextField
                                label={t('delivery.correction.quantity')}
                                inputMode="decimal"
                                dir="ltr"
                                value={form.data.quantities[String(l.id)] ?? ''}
                                onChange={(e) =>
                                    form.setData('quantities', {
                                        ...form.data.quantities,
                                        [String(l.id)]: e.target.value,
                                    })
                                }
                                optional
                            />
                        </div>
                    ))}
                    {note.can.correct_needs_destination && (
                        <RadioGroup
                            legend={t('delivery.correction.destination')}
                            name="destination"
                            value={form.data.destination}
                            onChange={(v) => form.setData('destination', v)}
                            options={[
                                {
                                    value: 'reservation',
                                    label: t(
                                        'delivery.correction.to_reservation',
                                    ),
                                },
                                {
                                    value: 'stock',
                                    label: t('delivery.correction.to_stock'),
                                },
                            ]}
                            required
                            error={errors.destination}
                        />
                    )}
                    <TextareaField
                        label={t('delivery.correction.reason')}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        required
                        error={errors.reason ?? errors.lines}
                    />
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            loading={form.processing}
                            disabled={form.processing || !ready}
                            onClick={submit}
                            data-test="correction-submit"
                        >
                            {t('delivery.correction.submit')}
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}

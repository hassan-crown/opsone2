import { Head, Link, useForm } from '@inertiajs/react';
import DeliveryController from '@/actions/App/Http/Controllers/Delivery/DeliveryController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { KindLabel, QtyText } from '@/components/delivery/shared';
import { TextField, TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { parseQty } from '@/components/procurement/quantity';
import {
    BackLink,
    FormErrors,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { OrderRef, OwedLine } from '@/types/delivery';

type Props = { order: OrderRef; lines: OwedLine[] };

/** DelNew: a delivery draft for an order — the actual quantity per line, never above what is deliverable. */
export default function DeliveryForm({ order, lines }: Props) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const form = useForm({
        note: '',
        quantities: lines.map(() => ''),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            note: data.note,
            lines: lines.map((l, i) => ({
                order_line_id: l.order_line_id,
                quantity: data.quantities[i].trim(),
            })),
            idempotency_key: key,
        }));
        form.post(DeliveryController.store.url(order.id), {
            preserveScroll: true,
            onError: renew,
        });
    };

    const anyPositive = form.data.quantities.some(
        (q) => (parseQty(q) ?? 0) > 0,
    );
    const orderUrl = OrderController.show.url(order.id);

    return (
        <>
            <Head title={t('delivery.new_title', { number: order.number })} />
            <PageHeader
                title={t('delivery.new_title', { number: order.number })}
                description={`${order.customer} · ${t(`delivery.kind.${order.kind}` as TranslationKey)}`}
                back={<BackLink href={orderUrl} label={order.number} />}
            />
            <form
                onSubmit={submit}
                className="grid gap-4"
                data-test="delivery-form"
            >
                <FormErrors errors={errors} />
                <Card className="gap-4">
                    <h2 className="text-heading text-strong">
                        {t('delivery.items')}
                    </h2>
                    <ul className="grid gap-3">
                        {lines.map((l, i) => {
                            const milli = parseQty(form.data.quantities[i]);
                            const max = parseQty(l.deliverable) ?? 0;
                            const over = milli !== null && milli > max;

                            return (
                                <li
                                    key={l.order_line_id}
                                    data-test="delivery-line"
                                    className="grid gap-3 rounded-md border border-stroke-weak p-3 md:grid-cols-[1fr_12rem]"
                                >
                                    <div>
                                        <p className="font-semibold text-strong">
                                            {l.name || l.description}
                                        </p>
                                        <p className="text-caption text-weak">
                                            {t('delivery.ordered')}{' '}
                                            <QtyText
                                                value={l.ordered}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t(
                                                'delivery.delivered_before',
                                            )}{' '}
                                            <QtyText
                                                value={l.delivered}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t('delivery.deliverable')}{' '}
                                            <QtyText
                                                value={l.deliverable}
                                                unit={l.unit}
                                            />
                                        </p>
                                    </div>
                                    <TextField
                                        label={t('delivery.this_delivery')}
                                        inputMode="decimal"
                                        dir="ltr"
                                        value={form.data.quantities[i]}
                                        onChange={(e) =>
                                            form.setData(
                                                'quantities',
                                                form.data.quantities.map(
                                                    (q, n) =>
                                                        n === i
                                                            ? e.target.value
                                                            : q,
                                                ),
                                            )
                                        }
                                        error={
                                            errors[`lines.${i}.quantity`] ??
                                            (milli === null
                                                ? t('delivery.error.quantity')
                                                : over
                                                  ? t(
                                                        'delivery.error.over_deliverable',
                                                        {
                                                            remaining:
                                                                l.deliverable,
                                                        },
                                                    )
                                                  : undefined)
                                        }
                                    />
                                </li>
                            );
                        })}
                    </ul>
                    {errors.lines && (
                        <p
                            className="text-caption text-danger-text"
                            role="alert"
                        >
                            {errors.lines}
                        </p>
                    )}
                </Card>
                <Card>
                    <TextareaField
                        label={t('delivery.note')}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        optional
                        error={errors.note}
                    />
                </Card>
                <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.processing || !anyPositive}
                        data-test="save-draft"
                    >
                        {t('delivery.save_draft')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={orderUrl}>{t('catalog.cancel')}</Link>
                    </Button>
                </div>
                <p className="text-caption text-weak">
                    <KindLabel kind={order.kind} />
                    {' — '}
                    {t(`delivery.hint.${order.kind}` as TranslationKey)}
                </p>
            </form>
        </>
    );
}

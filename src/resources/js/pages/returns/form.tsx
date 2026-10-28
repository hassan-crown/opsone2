import { Head, Link, useForm } from '@inertiajs/react';
import ReturnController from '@/actions/App/Http/Controllers/Returns/ReturnController';
import { QtyText } from '@/components/delivery/shared';
import { TextField, TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { parseQty } from '@/components/procurement/quantity';
import {
    BackLink,
    FormErrors,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { OverdueBadge } from '@/components/returns/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { EligibleLine, ReturnOrderRef } from '@/types/returns';

type Props = {
    order: ReturnOrderRef;
    lines: EligibleLine[];
    window_days: number;
};

/** ReturnNew: quantities of delivered lines asked back, with a reason; never above what is still eligible. */
export default function ReturnForm({ order, lines, window_days }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();
    const form = useForm({ reason: '', quantities: lines.map(() => '') });
    const errors = form.errors as Record<string, string | undefined>;
    const listUrl = ReturnController.index.url();

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            reason: data.reason,
            lines: lines.map((l, i) => ({
                order_line_id: l.order_line_id,
                quantity: data.quantities[i].trim(),
            })),
            idempotency_key: key,
        }));
        form.post(ReturnController.store.url(order.id), {
            preserveScroll: true,
            onError: renew,
        });
    };

    const anyPositive = form.data.quantities.some(
        (q) => (parseQty(q) ?? 0) > 0,
    );

    return (
        <>
            <Head title={t('returns.new_title', { number: order.number })} />
            <PageHeader
                title={t('returns.new_title', { number: order.number })}
                description={order.customer}
                back={<BackLink href={listUrl} label={t('returns.title')} />}
            />
            <form
                onSubmit={submit}
                className="grid gap-4"
                data-test="return-form"
            >
                <FormErrors errors={errors} />
                <Card className="gap-4">
                    <h2 className="text-heading text-strong">
                        {t('returns.items')}
                    </h2>
                    <p className="text-caption text-weak">
                        {t('returns.window_note', { days: window_days })}
                    </p>
                    <ul className="grid gap-3">
                        {lines.map((l, i) => {
                            const milli = parseQty(form.data.quantities[i]);
                            const max = parseQty(l.eligible) ?? 0;
                            const over = milli !== null && milli > max;

                            return (
                                <li
                                    key={l.order_line_id}
                                    data-test="return-line"
                                    className="grid gap-3 rounded-md border border-stroke-weak p-3 md:grid-cols-[1fr_12rem]"
                                >
                                    <div className="grid gap-1">
                                        <p className="font-semibold text-strong">
                                            {l.name}
                                        </p>
                                        <p className="text-caption text-weak">
                                            {t('returns.delivered')}{' '}
                                            <QtyText
                                                value={l.delivered}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t('returns.held')}{' '}
                                            <QtyText
                                                value={l.held}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t('returns.eligible_qty')}{' '}
                                            <QtyText
                                                value={l.eligible}
                                                unit={l.unit}
                                            />
                                        </p>
                                        {l.delivered_on && (
                                            <p className="flex flex-wrap items-center gap-2 text-caption text-weak">
                                                {t('returns.delivered_on')}{' '}
                                                {formatDate(
                                                    l.delivered_on,
                                                    locale,
                                                )}
                                                {l.overdue && (
                                                    <OverdueBadge
                                                        days={window_days}
                                                    />
                                                )}
                                            </p>
                                        )}
                                    </div>
                                    <TextField
                                        label={t('returns.this_return')}
                                        inputMode="decimal"
                                        dir="ltr"
                                        disabled={max === 0}
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
                                                ? t('returns.error.quantity')
                                                : over
                                                  ? t(
                                                        'returns.error.over_eligible',
                                                        {
                                                            remaining:
                                                                l.eligible,
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
                        label={t('returns.reason')}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        required
                        error={errors.reason}
                    />
                </Card>
                <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={
                            form.processing ||
                            !anyPositive ||
                            form.data.reason.trim().length < 3
                        }
                        data-test="submit-return"
                    >
                        {t('returns.submit')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={listUrl}>{t('catalog.cancel')}</Link>
                    </Button>
                </div>
                <p className="text-caption text-weak">
                    {t('returns.hint_request')}
                </p>
            </form>
        </>
    );
}

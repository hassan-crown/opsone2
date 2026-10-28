import { Form, Head, Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import {
    DateText,
    Money,
    Section,
    StatusBadge,
} from '@/components/portal/parts';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { show as showOrder } from '@/routes/customer/orders';
import { resubmit, store } from '@/routes/customer/proofs';
import type { PortalFinance, PortalOrder } from '@/types/portal';

type Props = {
    order: PortalOrder;
    finance: PortalFinance;
    accepts_proofs: boolean;
    replaces: string | null;
};

/**
 * PayOrder / PayPending (P-68): the amount due and «paid by transfer? attach the receipt». The receipt is under review
 * until the facility confirms it. Online payment arrives with the store wave.
 */
export default function PortalPay({
    order,
    finance,
    accepts_proofs,
    replaces,
}: Props) {
    const t = useT();
    const today = new Date().toISOString().slice(0, 10);
    const action = replaces ? resubmit.form(replaces) : store.form(order.id);
    const open = finance.dues.filter((due) => due.status !== 'paid');

    return (
        <>
            <Head title={t('portal.pay.title')} />
            <Link
                href={showOrder(order.id).url}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                <bdi dir="ltr">{order.number}</bdi>
            </Link>
            <h1 className="text-title text-strong">
                {finance.due_now_minor > 0 ? (
                    <>
                        {t('portal.pay.pay')}{' '}
                        <Money minor={finance.due_now_minor} />
                    </>
                ) : (
                    t('portal.pay.title')
                )}
            </h1>
            <p className="mt-1 mb-6 text-body text-weak">
                {t('portal.pay.lead')}
            </p>

            {open.length > 0 && (
                <Section title={t('portal.pay.schedule')}>
                    <ul className="divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-card">
                        {open.map((due) => (
                            <li
                                key={due.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-3 text-body"
                            >
                                <span className="text-strong">
                                    {due.name}
                                    {due.due_on && (
                                        <span className="ms-2 text-caption text-weak">
                                            <DateText value={due.due_on} />
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center gap-2">
                                    <Money minor={due.remaining_minor} />
                                    <StatusBadge
                                        scope="due"
                                        status={due.status}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {finance.pending_proofs_minor > 0 && (
                <div className="mb-6 flex gap-2 rounded-lg bg-info-bg p-3 text-body text-info-text">
                    <Icon icon={Info} className="mt-0.5" />
                    <p>
                        {t('portal.order.pending_note')}{' '}
                        <Money minor={finance.pending_proofs_minor} />
                    </p>
                </div>
            )}

            {accepts_proofs ? (
                <Section
                    title={
                        replaces
                            ? t('portal.pay.replace')
                            : t('portal.pay.send_receipt')
                    }
                >
                    <Card>
                        <Form
                            {...action}
                            className="flex flex-col gap-4"
                            encType="multipart/form-data"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <TextField
                                        label={t('portal.pay.amount')}
                                        name="amount"
                                        inputMode="decimal"
                                        dir="ltr"
                                        required
                                        defaultValue={
                                            finance.due_now_minor > 0
                                                ? (
                                                      finance.due_now_minor /
                                                      100
                                                  ).toFixed(2)
                                                : ''
                                        }
                                        error={errors.amount}
                                    />
                                    <SelectField
                                        label={t('portal.pay.method')}
                                        name="method"
                                        required
                                        defaultValue="transfer"
                                        options={[
                                            {
                                                value: 'transfer',
                                                label: t(
                                                    'portal.pay.method_transfer',
                                                ),
                                            },
                                            {
                                                value: 'cheque',
                                                label: t(
                                                    'portal.pay.method_cheque',
                                                ),
                                            },
                                        ]}
                                        error={errors.method}
                                    />
                                    <TextField
                                        label={t('portal.pay.paid_on')}
                                        name="paid_on"
                                        type="date"
                                        max={today}
                                        defaultValue={today}
                                        required
                                        error={errors.paid_on}
                                    />
                                    <TextField
                                        label={t('portal.pay.reference')}
                                        name="reference"
                                        dir="ltr"
                                        optional
                                        maxLength={80}
                                        error={errors.reference}
                                    />
                                    <TextField
                                        label={t('portal.pay.file')}
                                        hint={t('portal.pay.file_hint')}
                                        name="file"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        required
                                        error={errors.file}
                                    />
                                    {errors.transition && (
                                        <p className="text-caption text-danger-text">
                                            {errors.transition}
                                        </p>
                                    )}
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('portal.pay.submit')}
                                    </Button>
                                    <p className="text-caption text-weak">
                                        {t('portal.pay.review_note')}
                                    </p>
                                </>
                            )}
                        </Form>
                    </Card>
                </Section>
            ) : (
                <p className="text-body text-weak">{t('portal.pay.not_yet')}</p>
            )}
        </>
    );
}

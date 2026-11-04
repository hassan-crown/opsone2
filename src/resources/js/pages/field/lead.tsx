import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CircleCheck,
    MessageCircle,
    Phone,
    Repeat,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import FieldLeadController from '@/actions/App/Http/Controllers/FieldSales/FieldLeadController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { FieldLeadDetail } from '@/types/field';

type Props = {
    lead: FieldLeadDetail;
    order: { id: number; number: string } | null;
    closeReasons: string[];
};

/** `05…` / `+9665…` → digits for a wa.me link. */
function whatsappNumber(mobile: string): string {
    const digits = mobile.replace(/\D/g, '');

    return digits.startsWith('0') ? `966${digits.slice(1)}` : digits;
}

/**
 * One lead that follows the field sales rep (ADR-0041): who, what they need and by when to answer; call or message,
 * take it, close it with a reason or convert it into an order — the server's M-03 actions check each step again.
 */
export default function FieldLead({ lead, order, closeReasons }: Props) {
    const t = useT();
    const [closing, setClosing] = useState(false);
    const [busy, setBusy] = useState<'follow' | 'convert' | null>(null);
    const close = useForm({ reason: closeReasons[0] ?? 'other', note: '' });

    const post = (action: 'follow' | 'convert') => {
        setBusy(action);
        router.post(
            action === 'follow'
                ? FieldLeadController.follow.url({ interest: lead.id })
                : FieldLeadController.convert.url({ interest: lead.id }),
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <>
            <Head title={lead.name} />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Link
                    href={FieldController.index.url()}
                    className="text-label text-link"
                >
                    {t('field.back')}
                </Link>
                <span className="ms-auto flex flex-wrap gap-2">
                    <Badge variant="info" data-test="lead-status">
                        {t(`interests.status.${lead.status}` as TranslationKey)}
                    </Badge>
                    {lead.due_at && (
                        <Badge variant={lead.overdue ? 'danger' : 'neutral'}>
                            <bdi dir="ltr">
                                {lead.overdue
                                    ? t('field_sales.overdue', {
                                          at: lead.due_at,
                                      })
                                    : t('field_sales.due', { at: lead.due_at })}
                            </bdi>
                        </Badge>
                    )}
                </span>
            </div>

            <div className="flex flex-col gap-4">
                <section className="rounded-lg border border-stroke-weak bg-surface p-4">
                    <h1 className="text-heading m-0 text-strong">
                        {lead.name}
                    </h1>
                    <p className="mt-1 mb-0 text-caption text-weak">
                        {lead.reference} · {lead.branch} ·{' '}
                        {t(
                            `interests.channel.${lead.channel}` as TranslationKey,
                        )}
                    </p>
                    <h2 className="mt-4 mb-1 text-label text-strong">
                        {t('field_sales.lead.need')}
                    </h2>
                    <p className="m-0 text-body whitespace-pre-line">
                        {lead.need}
                    </p>
                    {lead.note && (
                        <>
                            <h2 className="mt-4 mb-1 text-label text-strong">
                                {t('field_sales.lead.dispatch_note')}
                            </h2>
                            <p className="m-0 text-body whitespace-pre-line">
                                {lead.note}
                            </p>
                        </>
                    )}
                    {lead.mobile && (
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            <Button asChild variant="secondary">
                                <a href={`tel:${lead.mobile}`}>
                                    <Icon icon={Phone} />
                                    {t('field.call')}
                                </a>
                            </Button>
                            <Button asChild variant="secondary">
                                <a
                                    href={`https://wa.me/${whatsappNumber(lead.mobile)}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Icon icon={MessageCircle} />
                                    {t('field_sales.whatsapp')}
                                </a>
                            </Button>
                        </div>
                    )}
                </section>

                {order && (
                    <section className="rounded-lg border border-stroke-weak bg-success-bg p-4 text-success-text">
                        <p className="m-0 text-body">
                            {t('field_sales.lead.converted', {
                                number: order.number,
                            })}
                        </p>
                        <Link
                            href={OrderController.show.url({ order: order.id })}
                            className="text-label text-link"
                        >
                            {t('field_sales.lead.open_order')}
                        </Link>
                    </section>
                )}

                {(lead.can.follow || lead.can.convert || lead.can.close) && (
                    <section
                        className="flex flex-col gap-3 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-label={t('field_sales.lead.actions')}
                    >
                        {lead.can.follow && (
                            <Button
                                type="button"
                                variant="secondary"
                                block
                                loading={busy === 'follow'}
                                onClick={() => post('follow')}
                                data-test="lead-follow"
                            >
                                <Icon icon={Repeat} />
                                {t('field_sales.lead.follow')}
                            </Button>
                        )}
                        {lead.can.convert && (
                            <>
                                <Button
                                    type="button"
                                    block
                                    loading={busy === 'convert'}
                                    onClick={() => post('convert')}
                                    data-test="lead-convert"
                                >
                                    <Icon icon={CircleCheck} />
                                    {t('field_sales.lead.convert')}
                                </Button>
                                <p className="m-0 text-caption text-weak">
                                    {t('field_sales.lead.convert_hint')}
                                </p>
                            </>
                        )}
                        {lead.can.close && !closing && (
                            <Button
                                type="button"
                                variant="ghost"
                                block
                                onClick={() => setClosing(true)}
                            >
                                <Icon icon={XCircle} />
                                {t('field_sales.lead.close')}
                            </Button>
                        )}
                        {lead.can.close && closing && (
                            <form
                                className="flex flex-col gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    close.post(
                                        FieldLeadController.close.url({
                                            interest: lead.id,
                                        }),
                                    );
                                }}
                            >
                                <SelectField
                                    label={t('field_sales.lead.close_reason')}
                                    value={close.data.reason}
                                    error={close.errors.reason}
                                    onChange={(e) =>
                                        close.setData('reason', e.target.value)
                                    }
                                    options={closeReasons.map((reason) => ({
                                        value: reason,
                                        label: t(
                                            `interests.reason.${reason}` as TranslationKey,
                                        ),
                                    }))}
                                />
                                <TextareaField
                                    label={t('field_sales.lead.close_note')}
                                    optional
                                    value={close.data.note}
                                    error={close.errors.note}
                                    onChange={(e) =>
                                        close.setData('note', e.target.value)
                                    }
                                />
                                <div className="grid grid-cols-2 gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setClosing(false)}
                                    >
                                        {t('field_sales.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        loading={close.processing}
                                    >
                                        {t('field_sales.lead.close_confirm')}
                                    </Button>
                                </div>
                            </form>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

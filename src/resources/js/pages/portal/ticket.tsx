import { Form, Head, Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { TextareaField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { DateText, FileLinks, Section } from '@/components/portal/parts';
import { TicketStatusBadge } from '@/components/portal/ticket-parts';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { show as showOrder } from '@/routes/customer/orders';
import { close, index, reply } from '@/routes/customer/tickets';
import type { CustomerTicket } from '@/types/tickets';

type Props = { ticket: CustomerTicket; max_files: number };

/** TicketPage (P-71): the public conversation, a reply box (a reply to a resolved/closed ticket reopens it) and close. */
export default function PortalTicket({ ticket, max_files }: Props) {
    const t = useT();
    const note =
        ticket.status === 'awaiting_customer'
            ? 'tickets.awaiting_note'
            : ticket.status === 'resolved'
              ? 'tickets.resolved_note'
              : ticket.status === 'closed'
                ? 'tickets.closed_note'
                : null;

    return (
        <>
            <Head title={ticket.number} />
            <Link
                href={index().url}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                {t('tickets.back')}
            </Link>
            <div className="flex flex-wrap items-center gap-2">
                <bdi dir="ltr" className="text-label text-strong">
                    {ticket.number}
                </bdi>
                <TicketStatusBadge status={ticket.status} />
            </div>
            <h1 className="mt-1 text-title text-strong">{ticket.subject}</h1>
            <p className="mt-1 mb-6 text-body text-weak">
                {t(`tickets.type.${ticket.type}` as TranslationKey)}
                {ticket.order && (
                    <>
                        {t('portal.sep')}
                        <Link
                            href={showOrder(ticket.order.id).url}
                            className="text-link underline-offset-4 hover:underline"
                        >
                            <bdi dir="ltr">{ticket.order.number}</bdi>
                        </Link>
                    </>
                )}
                {t('portal.sep')}
                <DateText value={ticket.created_at} />
            </p>

            {note && (
                <div className="mb-6 flex gap-2 rounded-lg bg-info-bg p-3 text-body text-info-text">
                    <Icon icon={Info} className="mt-0.5" />
                    <p>{t(note as TranslationKey)}</p>
                </div>
            )}

            <Section title={t('tickets.conversation')}>
                <ol className="flex flex-col gap-3">
                    {ticket.messages.map((message) => (
                        <li
                            key={message.id}
                            className={
                                message.mine
                                    ? 'ms-6 rounded-lg bg-brand-subtle p-3'
                                    : 'me-6 rounded-lg border border-stroke-weak bg-card p-3'
                            }
                        >
                            <p className="mb-1 flex flex-wrap items-center gap-2 text-caption text-weak">
                                <span className="text-label text-strong">
                                    {message.mine
                                        ? t('tickets.you')
                                        : t('tickets.support')}
                                </span>
                                <DateText value={message.created_at} time />
                            </p>
                            <p className="text-body whitespace-pre-wrap text-strong">
                                {message.body}
                            </p>
                            {message.files.length > 0 && (
                                <div className="mt-2">
                                    <FileLinks files={message.files} />
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            </Section>

            <Card>
                <Form
                    {...reply.form(ticket.id)}
                    className="flex flex-col gap-4"
                    encType="multipart/form-data"
                    resetOnSuccess
                >
                    {({ processing, errors }) => (
                        <>
                            <TextareaField
                                label={t('tickets.field.reply')}
                                name="body"
                                required
                                maxLength={5000}
                                rows={4}
                                error={errors.body}
                            />
                            <TextField
                                label={t('tickets.field.files')}
                                hint={t('tickets.field.files_hint', {
                                    max: max_files,
                                })}
                                name="files[]"
                                type="file"
                                multiple
                                optional
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                error={errors.files ?? errors['files.0']}
                            />
                            {errors.transition && (
                                <p className="text-caption text-danger-text">
                                    {errors.transition}
                                </p>
                            )}
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('tickets.send')}
                            </Button>
                        </>
                    )}
                </Form>
            </Card>

            {ticket.can_close && (
                <Form {...close.form(ticket.id)} className="mt-4">
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            {t('tickets.close_ticket')}
                        </Button>
                    )}
                </Form>
            )}
        </>
    );
}

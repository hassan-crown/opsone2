import { Head, Link } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { DateText, Forward } from '@/components/portal/parts';
import { TicketStatusBadge } from '@/components/portal/ticket-parts';
import { buttonVariants } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { create, show } from '@/routes/customer/tickets';
import type { CustomerTicketRow } from '@/types/tickets';

type Props = { tickets: CustomerTicketRow[] };

/** Tickets (the «الدعم» section, P-71): the customer's own tickets with their status, and «new ticket». */
export default function PortalTickets({ tickets }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('tickets.title')} />
            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-title text-strong">
                        {t('tickets.title')}
                    </h1>
                    <p className="mt-1 text-body text-weak">
                        {t('tickets.lead')}
                    </p>
                </div>
                <Link href={create().url} className={buttonVariants()}>
                    {t('tickets.new')}
                </Link>
            </div>

            {tickets.length === 0 ? (
                <EmptyState
                    icon={LifeBuoy}
                    title={t('tickets.empty')}
                    description={t('tickets.empty_lead')}
                />
            ) : (
                <ul className="flex flex-col gap-3">
                    {tickets.map((ticket) => (
                        <li key={ticket.id}>
                            <Link
                                href={show(ticket.id).url}
                                className="flex items-center gap-3 rounded-lg border border-stroke-weak bg-card p-4 hover:bg-hover"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <bdi
                                            className="text-label text-strong"
                                            dir="ltr"
                                        >
                                            {ticket.number}
                                        </bdi>
                                        <TicketStatusBadge
                                            status={ticket.status}
                                        />
                                    </div>
                                    <p className="mt-1 truncate text-body text-strong">
                                        {ticket.subject}
                                    </p>
                                    <p className="mt-1 text-caption text-weak">
                                        {t(
                                            `tickets.type.${ticket.type}` as TranslationKey,
                                        )}
                                        {ticket.order && (
                                            <>
                                                {t('portal.sep')}
                                                <bdi dir="ltr">
                                                    {ticket.order.number}
                                                </bdi>
                                            </>
                                        )}
                                        {t('portal.sep')}
                                        <DateText value={ticket.created_at} />
                                    </p>
                                </div>
                                <Forward />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

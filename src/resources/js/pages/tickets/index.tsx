import { Head, Link } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import TicketController from '@/actions/App/Http/Controllers/Tickets/TicketController';
import { FilterBar, Pager } from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { TicketStatusBadge } from '@/components/portal/ticket-parts';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { Page } from '@/types/catalog';
import type { StaffTicketRow, TicketStatus } from '@/types/tickets';

type Props = {
    tickets: Page<StaffTicketRow>;
    filters: { status: TicketStatus | null; mine: boolean; q: string };
};

const STATUSES: TicketStatus[] = [
    'new',
    'in_progress',
    'awaiting_customer',
    'resolved',
    'closed',
];

/** TicketInbox (SHR-08, P-59): the customer tickets inside the user's scope (grant branches or assigned to them). */
export default function TicketsIndex({ tickets, filters }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const filtered = Boolean(filters.q || filters.status || filters.mine);

    return (
        <>
            <Head title={t('tickets.staff.title')} />
            <PageHeader
                title={t('tickets.staff.title')}
                description={t('tickets.staff.description')}
            />
            <FilterBar url={TicketController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('tickets.staff.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-56"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">
                        {t('tickets.staff.status')}
                    </Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('tickets.staff.all')}</option>
                        {STATUSES.map((status) => (
                            <option key={status} value={status}>
                                {t(
                                    `tickets.status.${status}` as TranslationKey,
                                )}
                            </option>
                        ))}
                    </select>
                </div>
                <label className="flex min-h-11 items-center gap-2 text-body text-strong">
                    <input
                        type="checkbox"
                        name="mine"
                        value="1"
                        defaultChecked={filters.mine}
                    />
                    {t('tickets.staff.mine')}
                </label>
            </FilterBar>

            {tickets.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={LifeBuoy}
                        title={t('tickets.staff.empty')}
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('tickets.staff.title')}>
                            <thead>
                                <tr>
                                    <Th>{t('tickets.staff.col_number')}</Th>
                                    <Th>{t('tickets.staff.col_subject')}</Th>
                                    <Th>{t('tickets.staff.col_customer')}</Th>
                                    <Th>{t('tickets.staff.col_order')}</Th>
                                    <Th>{t('tickets.staff.col_assignee')}</Th>
                                    <Th>{t('tickets.staff.col_status')}</Th>
                                    <Th>{t('tickets.staff.col_opened')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {tickets.data.map((ticket) => (
                                    <tr key={ticket.id} data-test="ticket-row">
                                        <Td className="font-semibold whitespace-nowrap">
                                            <Link
                                                href={TicketController.show.url(
                                                    ticket.id,
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                <bdi dir="ltr">
                                                    {ticket.number}
                                                </bdi>
                                            </Link>
                                        </Td>
                                        <Td>{ticket.subject}</Td>
                                        <Td>{ticket.customer}</Td>
                                        <Td className="whitespace-nowrap">
                                            {ticket.order ? (
                                                <bdi dir="ltr">
                                                    {ticket.order.number}
                                                </bdi>
                                            ) : (
                                                t('tickets.staff.no_order')
                                            )}
                                        </Td>
                                        <Td>
                                            {ticket.assignee?.name ??
                                                t('tickets.staff.unassigned')}
                                        </Td>
                                        <Td>
                                            <TicketStatusBadge
                                                status={ticket.status}
                                            />
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {formatDate(
                                                ticket.created_at,
                                                locale,
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <Pager
                page={tickets}
                url={TicketController.index.url()}
                query={{
                    q: filters.q,
                    status: filters.status,
                    mine: filters.mine ? '1' : null,
                }}
            />
        </>
    );
}

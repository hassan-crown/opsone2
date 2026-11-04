import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarPlus } from 'lucide-react';
import AppointmentRequestController from '@/actions/App/Http/Controllers/AppointmentRequests/AppointmentRequestController';
import { RequestStatusBadge } from '@/components/appointment-requests/parts';
import { FilterBar } from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { AppointmentRequestRow } from '@/types/appointment-requests';

type StatusFilter = 'open' | 'new' | 'assigned' | 'scheduled' | 'closed';

type Props = {
    requests: AppointmentRequestRow[];
    counts: Record<'new' | 'assigned' | 'scheduled' | 'closed', number>;
    filters: { status: StatusFilter; mine: boolean; branch: string };
    branches: { id: number; name: string }[];
};

/** Facility wall-clock values (`Y-m-d` / `Y-m-d H:i`) read as local time, never shifted by UTC parsing. */
const wall = (value: string) =>
    value.length === 10 ? `${value}T00:00` : value.replace(' ', 'T');

const SHORT_DAY: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
};

/** «From → to · time of day»: each date isolated, the arrow mirrored with the reading direction. */
function Preferred({ row }: { row: AppointmentRequestRow }) {
    const t = useT();
    const { locale } = useLocale();
    const from = row.preferred_from;
    const to = row.preferred_to;

    if (!from && !row.time_of_day) {
        return <>—</>;
    }

    return (
        <span className="flex flex-col">
            {from && (
                <span className="inline-flex items-center gap-x-1.5 whitespace-nowrap">
                    <bdi>{formatDate(wall(from), locale, SHORT_DAY)}</bdi>
                    {to && to !== from && (
                        <>
                            <Icon
                                icon={ArrowRight}
                                size="sm"
                                className="text-weak"
                                label={t('appointment_requests.range_to')}
                            />
                            <bdi>{formatDate(wall(to), locale, SHORT_DAY)}</bdi>
                        </>
                    )}
                </span>
            )}
            {row.time_of_day && (
                <span className="text-caption text-weak">
                    {t(
                        `appointment_requests.time.${row.time_of_day}` as TranslationKey,
                    )}
                </span>
            )}
        </span>
    );
}

const TABS: StatusFilter[] = ['open', 'new', 'assigned', 'scheduled', 'closed'];

/** The appointment request queue (ADR-0039): customers ask, staff call and book. Scoped by P-54. */
export default function AppointmentRequestsIndex({
    requests,
    counts,
    filters,
    branches,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const dateTime = (value: string) =>
        formatDate(wall(value), locale, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    const url = AppointmentRequestController.index.url();
    const count = (tab: StatusFilter) =>
        tab === 'open' ? counts.new + counts.assigned : counts[tab];

    return (
        <>
            <Head title={t('appointment_requests.title')} />
            <PageHeader
                title={t('appointment_requests.title')}
                description={t('appointment_requests.description')}
            />

            <nav aria-label={t('appointment_requests.filter.status')}>
                <ul className="mb-4 flex flex-wrap gap-2">
                    {TABS.map((tab) => (
                        <li key={tab}>
                            <Link
                                href={AppointmentRequestController.index.url({
                                    query: {
                                        status: tab,
                                        mine: filters.mine ? '1' : null,
                                        branch: filters.branch || null,
                                    },
                                })}
                                aria-current={
                                    filters.status === tab ? 'page' : undefined
                                }
                                className={cn(
                                    'inline-flex min-h-9 items-center gap-2 rounded-md border px-3 text-label no-underline',
                                    filters.status === tab
                                        ? 'border-brand bg-brand-subtle text-brand-subtle-text'
                                        : 'border-stroke-weak text-strong hover:bg-hover',
                                )}
                            >
                                {t(
                                    `appointment_requests.status.${tab}` as TranslationKey,
                                )}
                                <span className="text-caption tabular-nums">
                                    {count(tab)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>

            <FilterBar url={url} reset={filters.mine || filters.branch !== ''}>
                <input type="hidden" name="status" value={filters.status} />
                {branches.length > 1 && (
                    <div className="grid gap-1">
                        <Label htmlFor="f-branch">
                            {t('appointment_requests.filter.branch')}
                        </Label>
                        <select
                            id="f-branch"
                            name="branch"
                            defaultValue={filters.branch}
                            className="field-control w-48 text-control"
                        >
                            <option value="">
                                {t('appointment_requests.filter.all_branches')}
                            </option>
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <label className="flex min-h-11 items-center gap-2 text-body text-strong">
                    <input
                        type="checkbox"
                        name="mine"
                        value="1"
                        defaultChecked={filters.mine}
                    />
                    {t('appointment_requests.filter.mine')}
                </label>
            </FilterBar>

            {requests.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={CalendarPlus}
                        title={t('appointment_requests.empty')}
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('appointment_requests.title')}>
                            <thead>
                                <tr>
                                    <Th>
                                        {t(
                                            'appointment_requests.col.reference',
                                        )}
                                    </Th>
                                    <Th>
                                        {t('appointment_requests.col.customer')}
                                    </Th>
                                    <Th>
                                        {t('appointment_requests.col.kind')}
                                    </Th>
                                    <Th>
                                        {t(
                                            'appointment_requests.col.preferred',
                                        )}
                                    </Th>
                                    <Th>
                                        {t('appointment_requests.col.assignee')}
                                    </Th>
                                    <Th>{t('appointment_requests.col.due')}</Th>
                                    <Th>
                                        {t(
                                            'appointment_requests.filter.status',
                                        )}
                                    </Th>
                                    <Th>
                                        {t('appointment_requests.col.created')}
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.map((r) => (
                                    <tr key={r.id} data-test="request-row">
                                        <Td className="font-semibold whitespace-nowrap">
                                            <Link
                                                href={AppointmentRequestController.show.url(
                                                    r.id,
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                <bdi dir="ltr">
                                                    {r.reference}
                                                </bdi>
                                            </Link>
                                        </Td>
                                        <Td>
                                            <div>{r.name}</div>
                                            <div className="text-caption text-weak">
                                                <bdi dir="ltr">{r.phone}</bdi>
                                            </div>
                                            {r.city && (
                                                <div className="text-caption text-weak">
                                                    {r.city}
                                                </div>
                                            )}
                                        </Td>
                                        <Td>
                                            {t(
                                                `appointments.kind.${r.kind}` as TranslationKey,
                                            )}
                                            <div className="text-caption text-weak">
                                                {t(
                                                    `appointment_requests.source.${r.source}` as TranslationKey,
                                                )}
                                            </div>
                                        </Td>
                                        <Td>
                                            <Preferred row={r} />
                                        </Td>
                                        <Td>
                                            {r.assignee?.name ??
                                                t(
                                                    'appointment_requests.unassigned',
                                                )}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {r.due_at ? (
                                                <span className="inline-flex items-center gap-2">
                                                    <bdi>
                                                        {dateTime(r.due_at)}
                                                    </bdi>
                                                    {r.overdue && (
                                                        <Badge variant="danger">
                                                            {t(
                                                                'appointment_requests.overdue',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </Td>
                                        <Td>
                                            <RequestStatusBadge
                                                status={r.status}
                                            />
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            <bdi>{dateTime(r.created_at)}</bdi>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
        </>
    );
}

AppointmentRequestsIndex.layout = {
    breadcrumbs: [
        {
            title: 'appointment_requests.title',
            href: AppointmentRequestController.index(),
        },
    ],
};

import { Head, Link, router, usePage } from '@inertiajs/react';
import { MapPinned, Send } from 'lucide-react';
import { useState } from 'react';
import AppointmentRequestController from '@/actions/App/Http/Controllers/AppointmentRequests/AppointmentRequestController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import FieldDispatchController from '@/actions/App/Http/Controllers/FieldSales/FieldDispatchController';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Filter = 'unassigned' | 'assigned' | 'all';

type Assignment = {
    rep_id: number | null;
    rep: string | null;
    due_at: string | null;
    overdue: boolean;
};

type LeadRow = Assignment & {
    id: number;
    reference: string | null;
    name: string;
    need: string;
    status: string;
    branch_id: number;
    branch: string;
    follower: string | null;
};

type RequestRow = Assignment & {
    id: number;
    reference: string | null;
    name: string;
    kind: string;
    city: string | null;
    status: string;
    lock_version: number;
    branch_id: number;
    branch: string;
};

type Rep = { id: number; name: string; open: number; branches: number[] };

type Props = {
    filter: Filter;
    leads: LeadRow[];
    requests: RequestRow[];
    reps: Rep[];
};

const TABS: Filter[] = ['unassigned', 'assigned', 'all'];

/** Rep picker + send, for one lead or request; only reps who cover the record's branch are offered. */
function DispatchCell({
    row,
    reps,
    target,
    lockVersion,
}: {
    row: LeadRow | RequestRow;
    reps: Rep[];
    target: string;
    lockVersion?: number;
}) {
    const t = useT();
    const eligible = reps.filter((r) => r.branches.includes(row.branch_id));
    const [rep, setRep] = useState<string>(
        String(row.rep_id ?? eligible[0]?.id ?? ''),
    );
    const [busy, setBusy] = useState(false);
    const { errors } = usePage().props;

    if (eligible.length === 0) {
        return (
            <span className="text-caption text-weak">
                {t('field_sales.dispatch.no_rep')}
            </span>
        );
    }

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                router.post(
                    target,
                    { rep_id: Number(rep), lock_version: lockVersion ?? null },
                    { preserveScroll: true, onFinish: () => setBusy(false) },
                );
            }}
        >
            <label className="sr-only" htmlFor={`rep-${target}`}>
                {t('field_sales.dispatch.rep')}
            </label>
            <select
                id={`rep-${target}`}
                value={rep}
                onChange={(e) => setRep(e.target.value)}
                className="field-control w-44 text-control"
                data-test="dispatch-rep"
            >
                {eligible.map((r) => (
                    <option key={r.id} value={r.id}>
                        {t('field_sales.dispatch.rep_option', {
                            name: r.name,
                            count: r.open,
                        })}
                    </option>
                ))}
            </select>
            <Button
                type="submit"
                size="sm"
                variant={row.rep_id ? 'secondary' : 'default'}
                loading={busy}
                data-test="dispatch-submit"
            >
                <Icon icon={Send} size="sm" />
                {row.rep_id
                    ? t('field_sales.dispatch.reassign')
                    : t('field_sales.dispatch.assign')}
            </Button>
            {typeof errors.rep_id === 'string' && (
                <span className="w-full text-caption text-danger-text">
                    {errors.rep_id}
                </span>
            )}
        </form>
    );
}

function Due({ row }: { row: Assignment }) {
    const t = useT();
    if (!row.rep) {
        return <span className="text-weak">—</span>;
    }

    return (
        <div>
            <div>{row.rep}</div>
            {row.due_at && (
                <Badge variant={row.overdue ? 'danger' : 'neutral'}>
                    <bdi dir="ltr">
                        {row.overdue
                            ? t('field_sales.overdue', { at: row.due_at })
                            : t('field_sales.due', { at: row.due_at })}
                    </bdi>
                </Badge>
            )}
        </div>
    );
}

/**
 * «توزيع البيع الميداني» (ADR-0041): the supervisor hands open leads and appointment requests to field sales reps of
 * the record's branch and sees who follows what and by when. Scoped by P-105.
 */
export default function FieldSalesDispatch({
    filter,
    leads,
    requests,
    reps,
}: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('field_sales.dispatch.title')} />
            <PageHeader
                title={t('field_sales.dispatch.title')}
                description={t('field_sales.dispatch.description')}
            />

            <nav aria-label={t('field_sales.dispatch.filter')}>
                <ul className="mb-4 flex flex-wrap gap-2">
                    {TABS.map((tab) => (
                        <li key={tab}>
                            <Link
                                href={FieldDispatchController.index.url({
                                    query: { show: tab },
                                })}
                                aria-current={
                                    filter === tab ? 'page' : undefined
                                }
                                className={cn(
                                    'inline-flex min-h-9 items-center rounded-md border px-3 text-label no-underline',
                                    filter === tab
                                        ? 'border-brand bg-brand-subtle text-brand-subtle-text'
                                        : 'border-stroke-weak text-strong hover:bg-hover',
                                )}
                            >
                                {t(
                                    `field_sales.dispatch.show.${tab}` as TranslationKey,
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>

            <div className="grid gap-6">
                <Card className="gap-0 p-0 md:p-0">
                    <h2 className="m-0 px-4 pt-4 text-title text-strong">
                        {t('field_sales.dispatch.leads', {
                            count: leads.length,
                        })}
                    </h2>
                    {leads.length === 0 ? (
                        <EmptyState
                            icon={MapPinned}
                            title={t('field_sales.dispatch.empty')}
                            compact
                        />
                    ) : (
                        <TableWrap>
                            <Table
                                caption={t(
                                    'field_sales.dispatch.leads_caption',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>
                                            {t('field_sales.dispatch.col.lead')}
                                        </Th>
                                        <Th>
                                            {t(
                                                'field_sales.dispatch.col.branch',
                                            )}
                                        </Th>
                                        <Th>
                                            {t('field_sales.dispatch.col.rep')}
                                        </Th>
                                        <Th>
                                            {t(
                                                'field_sales.dispatch.col.dispatch',
                                            )}
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {leads.map((lead) => (
                                        <tr
                                            key={lead.id}
                                            data-test="dispatch-lead"
                                        >
                                            <Td>
                                                <Link
                                                    href={InterestController.show.url(
                                                        lead.id,
                                                    )}
                                                    className="text-link hover:underline"
                                                >
                                                    {lead.name}
                                                </Link>
                                                <div className="line-clamp-1 max-w-72 text-caption text-weak">
                                                    <bdi dir="ltr">
                                                        {lead.reference}
                                                    </bdi>{' '}
                                                    · {lead.need}
                                                </div>
                                            </Td>
                                            <Td>{lead.branch}</Td>
                                            <Td>
                                                <Due row={lead} />
                                            </Td>
                                            <Td>
                                                <DispatchCell
                                                    row={lead}
                                                    reps={reps}
                                                    target={FieldDispatchController.assignLead.url(
                                                        lead.id,
                                                    )}
                                                />
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                    )}
                </Card>

                <Card className="gap-0 p-0 md:p-0">
                    <h2 className="m-0 px-4 pt-4 text-title text-strong">
                        {t('field_sales.dispatch.requests', {
                            count: requests.length,
                        })}
                    </h2>
                    {requests.length === 0 ? (
                        <EmptyState
                            icon={MapPinned}
                            title={t('field_sales.dispatch.empty')}
                            compact
                        />
                    ) : (
                        <TableWrap>
                            <Table
                                caption={t(
                                    'field_sales.dispatch.requests_caption',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>
                                            {t(
                                                'field_sales.dispatch.col.request',
                                            )}
                                        </Th>
                                        <Th>
                                            {t(
                                                'field_sales.dispatch.col.branch',
                                            )}
                                        </Th>
                                        <Th>
                                            {t('field_sales.dispatch.col.rep')}
                                        </Th>
                                        <Th>
                                            {t(
                                                'field_sales.dispatch.col.dispatch',
                                            )}
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {requests.map((request) => (
                                        <tr
                                            key={request.id}
                                            data-test="dispatch-request"
                                        >
                                            <Td>
                                                <Link
                                                    href={AppointmentRequestController.show.url(
                                                        request.id,
                                                    )}
                                                    className="text-link hover:underline"
                                                >
                                                    {request.name}
                                                </Link>
                                                <div className="text-caption text-weak">
                                                    <bdi dir="ltr">
                                                        {request.reference}
                                                    </bdi>{' '}
                                                    ·{' '}
                                                    {t(
                                                        `appointments.kind.${request.kind}` as TranslationKey,
                                                    )}
                                                    {request.city
                                                        ? ` · ${request.city}`
                                                        : ''}
                                                </div>
                                            </Td>
                                            <Td>{request.branch}</Td>
                                            <Td>
                                                <Due row={request} />
                                            </Td>
                                            <Td>
                                                <DispatchCell
                                                    row={request}
                                                    reps={reps}
                                                    lockVersion={
                                                        request.lock_version
                                                    }
                                                    target={FieldDispatchController.assignRequest.url(
                                                        request.id,
                                                    )}
                                                />
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                    )}
                </Card>

                <Card>
                    <h2 className="m-0 text-title text-strong">
                        {t('field_sales.dispatch.reps')}
                    </h2>
                    {reps.length === 0 ? (
                        <p className="m-0 text-body text-weak">
                            {t('field_sales.dispatch.no_reps')}
                        </p>
                    ) : (
                        <ul className="m-0 grid list-none gap-2 p-0 sm:grid-cols-2 lg:grid-cols-3">
                            {reps.map((rep) => (
                                <li
                                    key={rep.id}
                                    className="flex items-center justify-between rounded-md border border-stroke-weak px-3 py-2"
                                    data-test="dispatch-rep-card"
                                >
                                    <span className="text-body text-strong">
                                        {rep.name}
                                    </span>
                                    <span className="text-caption text-weak">
                                        {t('field_sales.dispatch.open_work', {
                                            count: rep.open,
                                        })}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </>
    );
}

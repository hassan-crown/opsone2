import { Head, Link, usePage } from '@inertiajs/react';
import {
    CalendarCheck,
    ChevronLeft,
    ChevronRight,
    MapPin,
    Phone,
} from 'lucide-react';
import { useEffect } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import FieldLeadController from '@/actions/App/Http/Controllers/FieldSales/FieldLeadController';
import { ReachButtons, StatusBadge } from '@/components/field/field-bits';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { FieldDue, FieldVisitRow, FieldWork } from '@/types/field';

type Props = { today: string; visits: FieldVisitRow[]; work: FieldWork | null };

/** The response time of a dispatched lead or request (ADR-0041). */
function DueBadge({ due }: { due: FieldDue }) {
    const t = useT();
    if (!due.due_at) {
        return null;
    }

    return (
        <Badge variant={due.overdue ? 'danger' : 'neutral'} data-test="due">
            <bdi dir="ltr">
                {due.overdue
                    ? t('field_sales.overdue', { at: due.due_at })
                    : t('field_sales.due', { at: due.due_at })}
            </bdi>
        </Badge>
    );
}

/**
 * ADR-0041: the leads and appointment requests that follow a field sales rep, oldest response time first. A lead
 * opens its own screen; a request is a phone call to book the visit.
 */
function SalesWork({ work }: { work: FieldWork }) {
    const t = useT();
    const { locale } = useLocale();
    const Chevron = locale === 'ar' ? ChevronLeft : ChevronRight;

    return (
        <div className="mb-8 flex flex-col gap-6">
            <section aria-labelledby="field-leads" data-test="field-leads">
                <h2 id="field-leads" className="mb-2 text-label text-weak">
                    {t('field_sales.my_leads', { count: work.leads.length })}
                </h2>
                {work.leads.length === 0 ? (
                    <p className="m-0 text-caption text-weak">
                        {t('field_sales.no_leads')}
                    </p>
                ) : (
                    <ul className="m-0 flex list-none flex-col gap-3 p-0">
                        {work.leads.map((lead) => (
                            <li
                                key={lead.id}
                                className="rounded-lg border border-stroke-weak bg-surface p-4 shadow-xs"
                                data-test="field-lead"
                            >
                                <Link
                                    href={FieldLeadController.show.url({
                                        interest: lead.id,
                                    })}
                                    className="flex items-start gap-3 text-inherit no-underline"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-body text-strong">
                                                {lead.name}
                                            </span>
                                            <Badge variant="info">
                                                {t(
                                                    `interests.status.${lead.status}` as TranslationKey,
                                                )}
                                            </Badge>
                                            <DueBadge due={lead} />
                                        </div>
                                        <p className="mt-1 mb-0 line-clamp-2 text-caption text-weak">
                                            {lead.need}
                                        </p>
                                        <p className="mt-1 mb-0 text-caption text-weak">
                                            {lead.reference} · {lead.branch}
                                        </p>
                                    </div>
                                    <Icon
                                        icon={Chevron}
                                        className="mt-1 text-weak"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {work.requests.length > 0 && (
                <section
                    aria-labelledby="field-requests"
                    data-test="field-requests"
                >
                    <h2
                        id="field-requests"
                        className="mb-2 text-label text-weak"
                    >
                        {t('field_sales.my_requests', {
                            count: work.requests.length,
                        })}
                    </h2>
                    <ul className="m-0 flex list-none flex-col gap-3 p-0">
                        {work.requests.map((request) => (
                            <li
                                key={request.id}
                                className="rounded-lg border border-stroke-weak bg-surface p-4 shadow-xs"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-body text-strong">
                                        {request.name}
                                    </span>
                                    <Badge variant="neutral">
                                        {t(
                                            `appointments.kind.${request.kind}` as TranslationKey,
                                        )}
                                    </Badge>
                                    <DueBadge due={request} />
                                </div>
                                {(request.city || request.address) && (
                                    <p className="mt-1 mb-0 flex items-start gap-1 text-caption text-weak">
                                        <Icon
                                            icon={MapPin}
                                            size="sm"
                                            className="mt-0.5"
                                        />
                                        {[request.city, request.address]
                                            .filter(Boolean)
                                            .join(' — ')}
                                    </p>
                                )}
                                {request.preferred_from && (
                                    <p className="mt-1 mb-0 text-caption text-weak">
                                        {t('field_sales.preferred', {
                                            from: request.preferred_from,
                                            to:
                                                request.preferred_to ??
                                                request.preferred_from,
                                        })}
                                    </p>
                                )}
                                {request.note && (
                                    <p className="mt-1 mb-0 text-caption whitespace-pre-line text-weak">
                                        {request.note}
                                    </p>
                                )}
                                <Button
                                    asChild
                                    variant="secondary"
                                    block
                                    className="mt-3"
                                >
                                    <a href={`tel:${request.phone}`}>
                                        <Icon icon={Phone} />
                                        {t('field_sales.call_to_book')}
                                    </a>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

/**
 * «زياراتي»: the technician's installation visits for today and the next days, grouped by day. The service worker
 * keeps this list and each visit screen readable offline.
 */
export default function FieldIndex({ today, visits, work }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { version } = usePage();
    const Chevron = locale === 'ar' ? ChevronLeft : ChevronRight;

    useEffect(() => {
        navigator.serviceWorker?.ready
            .then((registration) =>
                registration.active?.postMessage({
                    type: 'warm',
                    version: version ?? '',
                    urls: [
                        ...visits.map((v) =>
                            FieldController.show.url({ appointment: v.id }),
                        ),
                        ...(work?.leads ?? []).map((l) =>
                            FieldLeadController.show.url({ interest: l.id }),
                        ),
                    ],
                }),
            )
            .catch(() => undefined);
    }, [visits, work, version]);

    const days = visits.reduce<Record<string, FieldVisitRow[]>>(
        (groups, visit) => {
            (groups[visit.date] ??= []).push(visit);

            return groups;
        },
        {},
    );
    const dayLabel = (date: string) => {
        const diff = Math.round(
            (Date.parse(date) - Date.parse(today)) / 86_400_000,
        );
        if (diff === 0) {
            return t('field.today');
        }
        if (diff === 1) {
            return t('field.tomorrow');
        }

        return new Intl.DateTimeFormat(
            locale === 'ar' ? 'ar-SA-u-ca-gregory' : 'en-GB',
            {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
            },
        ).format(new Date(`${date}T12:00:00`));
    };

    return (
        <>
            <Head title={t('field.my_visits')} />
            {work && <SalesWork work={work} />}

            <h1 className="text-heading mb-4 text-strong">
                {t('field.my_visits')}
            </h1>

            {visits.length === 0 && (
                <EmptyState
                    icon={CalendarCheck}
                    title={t('field.empty.title')}
                    description={t('field.empty.desc')}
                />
            )}

            <div className="flex flex-col gap-6">
                {Object.entries(days).map(([date, rows]) => (
                    <section key={date} aria-labelledby={`day-${date}`}>
                        <h2
                            id={`day-${date}`}
                            className="mb-2 text-label text-weak"
                        >
                            {dayLabel(date)}
                        </h2>
                        <ul className="m-0 flex list-none flex-col gap-3 p-0">
                            {rows.map((visit) => (
                                <li
                                    key={visit.id}
                                    className="rounded-lg border border-stroke-weak bg-surface p-4 shadow-xs"
                                    data-test="field-visit"
                                >
                                    <Link
                                        href={FieldController.show.url({
                                            appointment: visit.id,
                                        })}
                                        className="flex items-start gap-3 text-inherit no-underline"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span
                                                    className="text-label text-strong"
                                                    dir="ltr"
                                                >
                                                    {visit.start}–{visit.end}
                                                </span>
                                                <StatusBadge
                                                    status={visit.status}
                                                />
                                                {visit.kind &&
                                                    visit.kind !==
                                                        'installation' && (
                                                        <Badge variant="neutral">
                                                            {t(
                                                                `appointments.kind.${visit.kind}` as TranslationKey,
                                                            )}
                                                        </Badge>
                                                    )}
                                            </div>
                                            <p className="mt-1 mb-0 text-body text-strong">
                                                {visit.customer.name}
                                            </p>
                                            <p className="m-0 flex items-start gap-1 text-caption text-weak">
                                                <Icon
                                                    icon={MapPin}
                                                    size="sm"
                                                    className="mt-0.5"
                                                />
                                                {visit.address}
                                            </p>
                                            {visit.items.length > 0 && (
                                                <p className="mt-1 mb-0 text-caption text-weak">
                                                    {visit.items
                                                        .slice(0, 3)
                                                        .map(
                                                            (i) =>
                                                                `${i.name} × ${i.quantity}`,
                                                        )
                                                        .join('، ')}
                                                    {visit.items.length > 3 &&
                                                        ` +${visit.items.length - 3}`}
                                                </p>
                                            )}
                                            <p className="mt-1 mb-0 text-caption text-weak">
                                                {visit.order.number}
                                            </p>
                                        </div>
                                        <Icon
                                            icon={Chevron}
                                            className="mt-1 text-weak"
                                        />
                                    </Link>
                                    <div className="mt-3">
                                        <ReachButtons visit={visit} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </>
    );
}

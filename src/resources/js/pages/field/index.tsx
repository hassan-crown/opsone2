import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarCheck, ChevronLeft, ChevronRight, MapPin } from 'lucide-react';
import { useEffect } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import { ReachButtons, StatusBadge } from '@/components/field/field-bits';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { useLocale, useT } from '@/lib/i18n';
import type { FieldVisitRow } from '@/types/field';

type Props = { today: string; visits: FieldVisitRow[] };

/**
 * «زياراتي»: the technician's installation visits for today and the next days, grouped by day. The service worker
 * keeps this list and each visit screen readable offline.
 */
export default function FieldIndex({ today, visits }: Props) {
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
                    urls: visits.map((v) =>
                        FieldController.show.url({ appointment: v.id }),
                    ),
                }),
            )
            .catch(() => undefined);
    }, [visits, version]);

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

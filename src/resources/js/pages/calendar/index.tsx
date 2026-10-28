import { Head, Link, router } from '@inertiajs/react';
import { CalendarX, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import CalendarController from '@/actions/App/Http/Controllers/Calendar/CalendarController';
import {
    AppointmentCard,
    addDays,
    formatDay,
} from '@/components/calendar/shared';
import type { AppointmentRow } from '@/components/calendar/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Props = {
    view: 'week' | 'day';
    date: string;
    from: string;
    days: { date: string; working: boolean; today: boolean }[];
    appointments: AppointmentRow[];
    filters: { branch: string; member: string };
    branches: { id: number; name: string }[];
    members: { id: number; name: string }[];
    work: { starts_at: string; ends_at: string };
    timezone: string;
    canSchedule: boolean;
};

export default function CalendarIndex({
    view,
    date,
    days,
    appointments,
    filters,
    branches,
    members,
    timezone,
    canSchedule,
}: Props) {
    const t = useT();
    const { locale, dir } = useLocale();
    const step = view === 'day' ? 1 : 7;

    const go = (
        next: Partial<{
            view: string;
            date: string;
            branch: string;
            member: string;
        }>,
    ) => {
        const merged = {
            view,
            date,
            branch: filters.branch,
            member: filters.member,
            ...next,
        };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, v]) => v !== ''),
        );
        router.get(
            CalendarController.index.url({ query }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    // In RTL the "previous" arrow points right; the label says which way it goes.
    const PrevIcon = dir === 'rtl' ? ChevronRight : ChevronLeft;
    const NextIcon = dir === 'rtl' ? ChevronLeft : ChevronRight;

    const first = days[0].date;
    const last = days[days.length - 1].date;
    const range =
        view === 'day'
            ? formatDay(first, locale, {
                  weekday: 'long',
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
              })
            : `${formatDay(first, locale, { day: 'numeric', month: 'long' })} – ${formatDay(last, locale, { day: 'numeric', month: 'long', year: 'numeric' })}`;

    const byDay = (d: string) => appointments.filter((a) => a.date === d);

    return (
        <>
            <Head title={t('calendar.title')} />
            <PageHeader
                title={t('calendar.title')}
                description={t('calendar.description')}
                actions={
                    canSchedule ? (
                        <Button asChild>
                            <Link
                                href={CalendarController.create.url({
                                    query: view === 'day' ? { date } : {},
                                })}
                                data-test="new-appointment"
                            >
                                <Icon icon={Plus} />
                                {t('calendar.new')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        aria-label={t(
                            `calendar.prev.${view}` as 'calendar.prev.week',
                        )}
                        data-test="calendar-prev"
                        onClick={() => go({ date: addDays(date, -step) })}
                    >
                        <Icon icon={PrevIcon} />
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        data-test="calendar-today"
                        onClick={() => go({ date: '' })}
                    >
                        {t('calendar.today')}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        aria-label={t(
                            `calendar.next.${view}` as 'calendar.next.week',
                        )}
                        data-test="calendar-next"
                        onClick={() => go({ date: addDays(date, step) })}
                    >
                        <Icon icon={NextIcon} />
                    </Button>
                </div>
                <p
                    className="min-w-0 flex-[1_1_220px] font-semibold text-strong"
                    aria-live="polite"
                    data-test="calendar-range"
                >
                    {range}
                </p>
                <div className="grid w-full grid-cols-1 gap-3 sm:flex sm:w-auto">
                    <SelectField
                        label={t('calendar.view')}
                        value={view}
                        onChange={(e) => go({ view: e.target.value })}
                        options={[
                            { value: 'week', label: t('calendar.view.week') },
                            { value: 'day', label: t('calendar.view.day') },
                        ]}
                    />
                    <SelectField
                        label={t('calendar.filter.branch')}
                        value={filters.branch}
                        onChange={(e) => go({ branch: e.target.value })}
                        options={[
                            {
                                value: '',
                                label: t('calendar.filter.all_branches'),
                            },
                            ...branches.map((b) => ({
                                value: String(b.id),
                                label: b.name,
                            })),
                        ]}
                    />
                    <SelectField
                        label={t('calendar.filter.member')}
                        value={filters.member}
                        onChange={(e) => go({ member: e.target.value })}
                        options={[
                            {
                                value: '',
                                label: t('calendar.filter.all_members'),
                            },
                            ...members.map((m) => ({
                                value: String(m.id),
                                label: m.name,
                            })),
                        ]}
                    />
                </div>
            </div>
            <p className="mb-4 text-caption text-weak">
                {t('calendar.timezone', { zone: timezone })}
            </p>

            {view === 'week' ? (
                <div
                    className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-7 xl:gap-3"
                    data-test="calendar-week"
                >
                    {days.map((day) => {
                        const items = byDay(day.date);

                        return (
                            <section
                                key={day.date}
                                aria-label={formatDay(day.date, locale)}
                                data-date={day.date}
                                className="flex min-w-0 flex-col gap-2"
                            >
                                <h2
                                    className={cn(
                                        'rounded-md px-2.5 py-2 text-label',
                                        day.today
                                            ? 'bg-brand-subtle text-brand-subtle-text'
                                            : 'text-weak',
                                    )}
                                >
                                    <Link
                                        href={CalendarController.index.url({
                                            query: {
                                                view: 'day',
                                                date: day.date,
                                            },
                                        })}
                                        className="text-inherit no-underline hover:underline"
                                    >
                                        {formatDay(day.date, locale, {
                                            weekday: 'long',
                                            day: 'numeric',
                                        })}
                                    </Link>
                                    {!day.working && (
                                        <span className="ms-2 font-normal">
                                            · {t('calendar.non_working')}
                                        </span>
                                    )}
                                </h2>
                                {items.length === 0 ? (
                                    <p className="m-0 px-2.5 text-caption text-weak">
                                        {t('calendar.empty_day')}
                                    </p>
                                ) : (
                                    items.map((a) => (
                                        <AppointmentCard
                                            key={a.id}
                                            appointment={a}
                                        />
                                    ))
                                )}
                            </section>
                        );
                    })}
                </div>
            ) : (
                <section
                    aria-label={t('calendar.list_label', { day: range })}
                    data-test="calendar-day"
                    className="flex max-w-3xl flex-col gap-3"
                >
                    {!days[0].working && (
                        <p className="m-0 text-caption text-weak">
                            {t('calendar.non_working')}
                        </p>
                    )}
                    {appointments.length === 0 ? (
                        <EmptyState
                            icon={CalendarX}
                            title={t('calendar.empty_day')}
                            compact
                        />
                    ) : (
                        appointments.map((a) => (
                            <AppointmentCard
                                key={a.id}
                                appointment={a}
                                detailed
                            />
                        ))
                    )}
                </section>
            )}
        </>
    );
}

CalendarIndex.layout = {
    breadcrumbs: [{ title: 'calendar.nav', href: CalendarController.index() }],
};

import { Link } from '@inertiajs/react';
import { MapPin, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppointmentController from '@/actions/App/Http/Controllers/Calendar/AppointmentController';
import CalendarController from '@/actions/App/Http/Controllers/Calendar/CalendarController';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { api } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';
import type { Locale, TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type AppointmentRow = {
    id: number;
    kind: string;
    status: string;
    /** Facility-local day (Y-m-d) and wall times (H:i). */
    date: string;
    start: string;
    end: string;
    starts_at: string;
    ends_at: string;
    order: { id: number; number: string };
    branch: string;
    customer: string | null;
    members: { id: number; name: string }[];
    conflicted: boolean;
    no_show: boolean;
    has_pending_change: boolean;
    can_manage: boolean;
    maps_url: string;
    address: string;
};

export type ClashPreview = {
    conflicts: AppointmentRow[];
    hidden: number;
    allows_conflicts: boolean;
};

const STATUS_TONE = {
    proposed: 'neutral',
    awaiting_confirmation: 'warning',
    confirmed: 'info',
    done: 'success',
    cancelled: 'danger',
} as const;

export function StatusBadge({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge
            variant={
                STATUS_TONE[status as keyof typeof STATUS_TONE] ?? 'neutral'
            }
        >
            {t(`appointments.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** Digits stay Latin in both languages (design-system rule). */
const intlLocale = (locale: Locale) =>
    locale === 'ar' ? 'ar-u-nu-latn' : 'en-US';

/** A facility-local calendar day (`Y-m-d`) formatted in the UI language, independent of the browser time zone. */
export function formatDay(
    date: string,
    locale: Locale,
    options: Intl.DateTimeFormatOptions = {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    },
): string {
    return new Intl.DateTimeFormat(intlLocale(locale), {
        ...options,
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}

export function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

/** Adds minutes to a local `Y-m-dTH:i` wall time. */
export function addMinutes(local: string, minutes: number): string {
    const d = new Date(`${local}:00Z`);
    d.setUTCMinutes(d.getUTCMinutes() + minutes);

    return d.toISOString().slice(0, 16);
}

export function MapLink({
    url,
    className,
}: {
    url: string;
    className?: string;
}) {
    const t = useT();

    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(
                'inline-flex min-h-11 items-center gap-1 text-link hover:underline',
                className,
            )}
            data-test="appointment-map-link"
        >
            <Icon icon={MapPin} size="sm" />
            {t('appointments.map')}
        </a>
    );
}

export function AppointmentCard({
    appointment,
    detailed = false,
}: {
    appointment: AppointmentRow;
    detailed?: boolean;
}) {
    const t = useT();

    return (
        <Link
            href={AppointmentController.show.url(appointment.id)}
            data-test="appointment-card"
            className={cn(
                'flex min-w-0 flex-col gap-1 rounded-lg border bg-surface p-3 text-strong no-underline hover:bg-hover',
                appointment.conflicted
                    ? 'border-warning-text'
                    : 'border-stroke-weak',
                appointment.status === 'cancelled' && 'opacity-60',
            )}
        >
            <span className="flex flex-wrap items-center justify-between gap-1">
                <bdi dir="ltr" className="font-semibold">
                    {appointment.start}
                    {detailed && ` – ${appointment.end}`}
                </bdi>
                <StatusBadge status={appointment.status} />
            </span>
            <span className="font-semibold">
                {t(`appointments.kind.${appointment.kind}` as TranslationKey)}
            </span>
            <bdi
                dir="ltr"
                className="text-start font-mono text-caption text-weak"
            >
                {appointment.order.number}
            </bdi>
            {appointment.customer && (
                <span className="text-caption text-weak">
                    {appointment.customer}
                </span>
            )}
            <span className="text-caption text-weak">{appointment.branch}</span>
            {appointment.members.length > 0 && (
                <span className="text-caption text-weak">
                    {appointment.members.map((m) => m.name).join('، ')}
                </span>
            )}
            {detailed && (
                <span className="text-caption text-weak">
                    {appointment.address}
                </span>
            )}
            {(appointment.conflicted || appointment.has_pending_change) && (
                <span className="flex flex-wrap gap-1">
                    {appointment.conflicted && (
                        <Badge variant="warning">
                            <Icon icon={TriangleAlert} size="sm" />
                            {t('calendar.conflicted')}
                        </Badge>
                    )}
                    {appointment.has_pending_change && (
                        <Badge variant="info">
                            {t('calendar.change_pending')}
                        </Badge>
                    )}
                </span>
            )}
        </Link>
    );
}

/**
 * Live clash preview for a candidate window (`GET /calendar/conflicts`). Shows the clashing appointments the viewer
 * may see and only a count for the rest; the policy decides whether the user can still go on with a reason.
 */
export function useClashPreview(
    memberIds: string[],
    startsAt: string,
    endsAt: string,
    exclude?: number,
): ClashPreview | null {
    const [preview, setPreview] = useState<ClashPreview | null>(null);
    const key = `${memberIds.join(',')}|${startsAt}|${endsAt}`;

    useEffect(() => {
        let live = true;
        const valid =
            memberIds.length > 0 &&
            /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(startsAt) &&
            /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(endsAt) &&
            endsAt > startsAt;
        const timer = setTimeout(() => {
            if (!valid) {
                setPreview(null);

                return;
            }
            const query = new URLSearchParams({
                starts_at: startsAt,
                ends_at: endsAt,
            });
            memberIds.forEach((id) => query.append('member_ids[]', id));
            if (exclude !== undefined) {
                query.set('exclude', String(exclude));
            }
            void api<ClashPreview>(
                'GET',
                `${CalendarController.conflicts.url()}?${query}`,
            ).then((result) => {
                if (live) {
                    setPreview(result.ok ? result.data : null);
                }
            });
        }, 250);

        return () => {
            live = false;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, exclude]);

    return preview;
}

export function hasClash(preview: ClashPreview | null): boolean {
    return (
        preview !== null && (preview.conflicts.length > 0 || preview.hidden > 0)
    );
}

export function ClashPanel({
    preview,
    proposalOnly = false,
}: {
    preview: ClashPreview | null;
    proposalOnly?: boolean;
}) {
    const t = useT();
    const { locale } = useLocale();

    if (!hasClash(preview) || preview === null) {
        return null;
    }
    const allow = preview.allows_conflicts;

    return (
        <Alert
            variant={allow ? 'warning' : 'danger'}
            role="alert"
            data-test="clash-panel"
            data-policy={allow ? 'allow_with_reason' : 'block'}
        >
            <AlertBody>
                <AlertTitle>
                    {allow
                        ? t('appointments.conflict.title_allow')
                        : t('appointments.conflict.title_block')}
                </AlertTitle>
                <p>
                    {allow
                        ? t('appointments.conflict.body_allow')
                        : t('appointments.conflict.body_block')}
                </p>
                {proposalOnly && (
                    <p>{t('appointments.conflict.proposal_note')}</p>
                )}
                {preview.conflicts.length > 0 && (
                    <ul className="m-0 mt-3 flex list-none flex-col gap-2 p-0">
                        {preview.conflicts.map((c) => (
                            <li
                                key={c.id}
                                data-test="clash-item"
                                className="flex flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <span className="font-semibold">
                                    {formatDay(c.date, locale, {
                                        day: 'numeric',
                                        month: 'short',
                                    })}
                                </span>
                                <bdi dir="ltr" className="font-semibold">
                                    {c.start}–{c.end}
                                </bdi>
                                <span>
                                    {t(
                                        `appointments.kind.${c.kind}` as TranslationKey,
                                    )}
                                </span>
                                <bdi dir="ltr" className="font-mono">
                                    {c.order.number}
                                </bdi>
                                <span>
                                    {c.members.map((m) => m.name).join('، ')}
                                </span>
                                <Link
                                    href={AppointmentController.show.url(c.id)}
                                    className="text-link hover:underline"
                                >
                                    {t('appointments.conflict.open')}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {preview.hidden > 0 && (
                    <p className="mt-2" data-test="clash-hidden">
                        {preview.hidden === 1
                            ? t('appointments.conflict.hidden')
                            : t('appointments.conflict.hidden_many', {
                                  count: preview.hidden,
                              })}
                    </p>
                )}
            </AlertBody>
        </Alert>
    );
}

export function useDurationLabel() {
    const t = useT();

    return (minutes: number) => {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        if (h === 0) {
            return t('appointments.schedule.duration.minutes', { minutes: m });
        }

        return m === 0
            ? t('appointments.schedule.duration.hours', { hours: h })
            : t('appointments.schedule.duration.hours_minutes', {
                  hours: h,
                  minutes: m,
              });
    };
}

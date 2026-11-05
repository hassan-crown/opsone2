import { Navigation, Phone } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { STATUS_VARIANT } from '@/types/field';
import type { FieldVisitRow, InstallationStatus } from '@/types/field';

export function StatusBadge({ status }: { status: InstallationStatus }) {
    const t = useT();

    return (
        <Badge variant={STATUS_VARIANT[status]} data-test="installation-status">
            {t(`installation.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** One-tap call and directions: the two things a technician needs before anything else. */
export function ReachButtons({ visit }: { visit: FieldVisitRow }) {
    const t = useT();

    return (
        <div className="grid grid-cols-2 gap-2">
            <Button asChild variant="secondary">
                <a href={`tel:${visit.customer.phone}`}>
                    <Icon icon={Phone} />
                    {t('field.call')}
                </a>
            </Button>
            <Button asChild variant="secondary">
                <a href={visit.maps_url} target="_blank" rel="noreferrer">
                    <Icon icon={Navigation} />
                    {t('field.directions')}
                </a>
            </Button>
        </div>
    );
}

/** Minutes as «1 س 25 د» / «1 h 25 min». */
export function useDuration(): (minutes: number | null) => string {
    const t = useT();

    return (minutes) => {
        if (minutes === null) {
            return '—';
        }
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;

        return h > 0
            ? t('installation.duration.hm', { h, m })
            : t('installation.duration.m', { m });
    };
}

/**
 * A value inside a translated sentence (a date, a count), wrapped in a Unicode first-strong isolate: it keeps its own
 * direction and never reorders the words around it in RTL.
 */
export function isolate(value: string): string {
    return `\u2068${value}\u2069`;
}

/**
 * The field app's dates in the UI language with Latin digits. The server sends the facility's local wall time
 * («2026-11-05» or «2026-11-05 14:00»); it is formatted as UTC so the device's own time zone never shifts it.
 */
export function useFieldDates() {
    const { locale } = useLocale();
    const parse = (value: string) =>
        `${value.slice(0, 10)}T${value.slice(11, 16) || '12:00'}:00Z`;

    return {
        /** «الخميس، 5 نوفمبر» */
        day: (date: string) =>
            formatDate(parse(date), locale, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                timeZone: 'UTC',
            }),
        /** «5 نوفمبر» */
        date: (date: string) =>
            formatDate(parse(date), locale, {
                day: 'numeric',
                month: 'long',
                timeZone: 'UTC',
            }),
        /** «5 نوفمبر، 14:00» */
        stamp: (at: string) =>
            formatDate(parse(at), locale, {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
                timeZone: 'UTC',
            }),
    };
}

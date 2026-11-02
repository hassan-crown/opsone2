import { Navigation, Phone } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
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

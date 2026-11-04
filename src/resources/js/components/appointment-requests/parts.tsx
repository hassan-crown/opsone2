import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { AppointmentRequestStatus } from '@/types/appointment-requests';

const VARIANT: Record<
    AppointmentRequestStatus,
    'info' | 'warning' | 'success' | 'neutral'
> = {
    new: 'info',
    assigned: 'warning',
    scheduled: 'success',
    closed: 'neutral',
};

export function RequestStatusBadge({
    status,
}: {
    status: AppointmentRequestStatus;
}) {
    const t = useT();

    return (
        <Badge variant={VARIANT[status]}>
            {t(`appointment_requests.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** «2026-11-10 → 2026-11-14 · Morning», or a dash when the customer gave no preference. */
export function usePreferredLabel() {
    const t = useT();

    return (
        from: string | null,
        to: string | null,
        time: string | null,
    ): string => {
        const days = from ? (to && to !== from ? `${from} → ${to}` : from) : '';
        const part = time
            ? t(`appointment_requests.time.${time}` as TranslationKey)
            : '';

        return [days, part].filter(Boolean).join(' · ') || '—';
    };
}

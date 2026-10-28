import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { TicketStatus } from '@/types/tickets';

const TONES = {
    new: 'info',
    in_progress: 'brand',
    awaiting_customer: 'warning',
    resolved: 'success',
    closed: 'neutral',
} as const;

/** A ticket status in plain words, for the customer and staff screens alike. */
export function TicketStatusBadge({ status }: { status: TicketStatus }) {
    const t = useT();

    return (
        <Badge variant={TONES[status]}>
            {t(`tickets.status.${status}` as TranslationKey)}
        </Badge>
    );
}

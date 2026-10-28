import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { ReturnStatus } from '@/types/returns';

const VARIANT = {
    requested: 'warning',
    accepted: 'info',
    rejected: 'neutral',
    received: 'brand',
    inspected: 'success',
    cancelled: 'neutral',
} as const;

export function ReturnStatusBadge({ status }: { status: ReturnStatus }) {
    const t = useT();

    return (
        <Badge variant={VARIANT[status]}>
            {t(`returns.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** Past the facility's return window: an indicator for the decision, never a refusal. */
export function OverdueBadge({ days }: { days: number }) {
    const t = useT();

    return <Badge variant="warning">{t('returns.overdue', { days })}</Badge>;
}

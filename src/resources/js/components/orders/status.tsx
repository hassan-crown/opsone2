import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { OrderKind, OrderStageSummary } from './types';

const TONE = {
    in_progress: 'info',
    paused: 'warning',
    execution_finished: 'success',
    cancellation_pending: 'warning',
    cancelled: 'danger',
    closed: 'neutral',
    draft: 'neutral',
    awaiting_payment: 'warning',
    needs_attention: 'warning',
    confirmed: 'info',
    partially_delivered: 'info',
    delivered: 'success',
} as const;

export function StatusBadge({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge variant={TONE[status as keyof typeof TONE] ?? 'neutral'}>
            {t(`orders.status.${status}` as TranslationKey)}
        </Badge>
    );
}

export function KindBadge({ kind }: { kind: OrderKind }) {
    const t = useT();

    return (
        <Badge variant={kind === 'ready' ? 'brand' : 'neutral'}>
            {t(`orders.kind.${kind}` as TranslationKey)}
        </Badge>
    );
}

/** The stage an order is in now; a late stage reads as a warning with its days late. */
export function StageBadge({
    stage,
}: {
    stage: OrderStageSummary | null | undefined;
}) {
    const t = useT();

    if (!stage) {
        return <span className="text-weak">—</span>;
    }

    return (
        <Badge
            variant={stage.days_late > 0 ? 'warning' : 'neutral'}
            data-test="order-stage"
        >
            {stage.name}
            {stage.days_late > 0 && (
                <>
                    <span aria-hidden="true">·</span>
                    {t('orders.stage.late', { n: stage.days_late })}
                </>
            )}
        </Badge>
    );
}

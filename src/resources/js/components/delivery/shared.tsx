import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { DeliveryStatus } from '@/types/delivery';

const VARIANT = {
    draft: 'warning',
    confirmed: 'success',
    cancelled: 'neutral',
} as const;

export function DeliveryStatusBadge({ status }: { status: DeliveryStatus }) {
    const t = useT();

    return (
        <Badge variant={VARIANT[status]}>
            {t(`delivery.status.${status}` as TranslationKey)}
        </Badge>
    );
}

export function KindLabel({ kind }: { kind: 'custom' | 'ready' }) {
    const t = useT();

    return <>{t(`delivery.kind.${kind}` as TranslationKey)}</>;
}

/** A decimal quantity with left-to-right digits in both languages. */
export function QtyText({ value, unit }: { value: string; unit?: string | null }) {
    return (
        <span className="whitespace-nowrap tabular-nums">
            <bdi dir="ltr">{value}</bdi>
            {unit ? ` ${unit}` : ''}
        </span>
    );
}

import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

const DOCUMENT_TONE = {
    draft: 'neutral',
    awaiting_customer: 'warning',
    approved: 'success',
    changes_requested: 'info',
    expired: 'danger',
    replaced: 'neutral',
    cancelled: 'neutral',
} as const;

const CHANGE_TONE = {
    draft: 'neutral',
    awaiting_approval: 'warning',
    needs_revision: 'info',
    applied: 'success',
    cancelled: 'neutral',
} as const;

/** M-08 state; never colour alone — the label always says it. */
export function DocumentStatusBadge({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge
            variant={
                DOCUMENT_TONE[status as keyof typeof DOCUMENT_TONE] ?? 'neutral'
            }
            data-test={`document-status-${status}`}
        >
            {t(`agreements.document.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** M-09 state. */
export function ChangeStatusBadge({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge
            variant={
                CHANGE_TONE[status as keyof typeof CHANGE_TONE] ?? 'neutral'
            }
        >
            {t(`agreements.change.status.${status}` as TranslationKey)}
        </Badge>
    );
}

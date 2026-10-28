import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { DueStatus, FinanceProof } from './types';

type Variant = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const DUE: Record<DueStatus, Variant> = {
    upcoming: 'neutral',
    due: 'warning',
    overdue: 'danger',
    partially_paid: 'info',
    paid: 'success',
};

export function DueStatusBadge({ status }: { status: DueStatus }) {
    const t = useT();

    return (
        <Badge variant={DUE[status]}>
            {t(`finance.due_status.${status}` as TranslationKey)}
        </Badge>
    );
}

const PROOF: Record<FinanceProof['status'], Variant> = {
    under_review: 'warning',
    needs_correction: 'info',
    confirmed: 'success',
    cancelled: 'neutral',
};

export function ProofStatusBadge({
    status,
}: {
    status: FinanceProof['status'];
}) {
    const t = useT();

    return (
        <Badge variant={PROOF[status]}>
            {t(`finance.proof_status.${status}` as TranslationKey)}
        </Badge>
    );
}

const PAYMENT: Record<string, Variant> = {
    recorded: 'neutral',
    pending_confirmation: 'warning',
    unresolved: 'warning',
    confirmed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
    corrected: 'info',
};

export function PaymentStatusBadge({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge variant={PAYMENT[status] ?? 'neutral'}>
            {t(`finance.payment_status.${status}` as TranslationKey)}
        </Badge>
    );
}

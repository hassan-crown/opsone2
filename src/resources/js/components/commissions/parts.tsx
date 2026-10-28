import { bpToPercent, toMajor } from '@/components/agreement/money';
import { Field } from '@/components/opsone/field';
import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { Amount } from '@/components/opsone/amount';
import type { Entry, PayoutStatus } from './types';

const PAYOUT_TONE: Record<
    PayoutStatus,
    'neutral' | 'info' | 'success' | 'warning'
> = {
    draft: 'neutral',
    reserved: 'info',
    paid: 'success',
    cancelled: 'neutral',
    corrected: 'warning',
};

export function PayoutBadge({ status }: { status: PayoutStatus }) {
    const t = useT();

    return (
        <Badge variant={PAYOUT_TONE[status]}>
            {t(`commissions.payout.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** `5%` from basis points, LTR-isolated. */
export function Rate({ bp }: { bp: number }) {
    return <bdi dir="ltr">{bpToPercent(bp)}%</bdi>;
}

/** Halalas as an amount; a negative balance is shown in the danger colour (carried, never deducted). */
export function Minor({
    value,
    strong,
}: {
    value: number | null | undefined;
    strong?: boolean;
}) {
    const t = useT();
    if (value === null || value === undefined) {
        return <span className="text-weak">{t('commissions.hidden')}</span>;
    }

    return <Amount value={toMajor(value)} strong={strong} />;
}

export function FileInput({
    label,
    error,
    onChange,
}: {
    label: string;
    error?: string;
    onChange: (file: File | null) => void;
}) {
    return (
        <Field label={label} error={error} required>
            {(control) => (
                <input
                    {...control}
                    type="file"
                    className="field-control py-2"
                    onChange={(e) => onChange(e.target.files?.[0] ?? null)}
                    data-test="proof-input"
                />
            )}
        </Field>
    );
}

const SOURCES = [
    'order_collection',
    'agreement',
    'sale_correction',
    'refund',
    'correction',
];

/** Label of an accrual difference by what caused it (6c may add sources: unknown ones read «other»). */
export function entryKey(
    entry: Pick<Entry, 'kind' | 'source'>,
): TranslationKey {
    if (entry.kind !== 'accrual') {
        return `commissions.entry.${entry.kind}` as TranslationKey;
    }

    return `commissions.entry.${SOURCES.includes(entry.source) ? entry.source : 'other'}` as TranslationKey;
}

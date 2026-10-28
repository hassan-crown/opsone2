import { Amount } from '@/components/opsone/amount';
import { useT } from '@/lib/i18n';
import { bpToPercent, toMajor } from './money';
import type { Discount, Totals } from './types';

function Row({
    label,
    minor,
    strong,
}: {
    label: string;
    minor: number | null | undefined;
    strong?: boolean;
}) {
    const t = useT();

    return (
        <div className="flex flex-wrap justify-between gap-2 border-b border-stroke-weak py-2 last:border-0">
            <dt className={strong ? 'font-semibold text-strong' : 'text-weak'}>
                {label}
            </dt>
            <dd>
                {minor === null || minor === undefined ? (
                    <span className="text-weak">
                        {t('agreements.amount.unknown')}
                    </span>
                ) : (
                    <Amount value={toMajor(minor)} strong={strong} />
                )}
            </dd>
        </div>
    );
}

/** The quote/agreement summary (before discount, discount, VAT, total). Render only when amounts are visible. */
export function TotalsSummary({
    totals,
    discount,
    vat,
}: {
    totals: Totals | undefined;
    discount: Discount | undefined;
    vat: { rate_bp: number; inclusive: boolean };
}) {
    const t = useT();

    if (!totals) {
        return null;
    }

    return (
        <dl data-test="totals">
            <Row
                label={t('agreements.totals.base')}
                minor={totals.base_minor}
            />
            {discount && discount.type !== 'none' && (
                <Row
                    label={
                        discount.type === 'percent'
                            ? t('agreements.totals.discount_percent', {
                                  percent: bpToPercent(discount.value),
                              })
                            : t('agreements.totals.discount')
                    }
                    minor={
                        totals.discount_minor === null
                            ? null
                            : -(totals.discount_minor ?? 0)
                    }
                />
            )}
            <Row
                label={t('agreements.totals.vat', {
                    percent: bpToPercent(vat.rate_bp),
                })}
                minor={totals.vat_minor}
            />
            <Row
                label={
                    vat.inclusive
                        ? t('agreements.totals.total_inclusive')
                        : t('agreements.totals.total')
                }
                minor={totals.total_minor}
                strong
            />
        </dl>
    );
}

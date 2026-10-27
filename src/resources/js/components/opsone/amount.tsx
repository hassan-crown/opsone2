import { formatAmount } from '@/lib/format';
import { useLocale } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type AmountProps = {
    value: number;
    currency?: string;
    strong?: boolean;
    className?: string;
};

/** Money with Latin tabular digits; the currency label follows the language (single formatter). */
export function Amount({
    value,
    currency = 'SAR',
    strong,
    className,
}: AmountProps) {
    const { locale } = useLocale();

    return (
        <bdi
            dir={locale === 'ar' ? 'rtl' : 'ltr'}
            className={cn(
                'whitespace-nowrap lining-nums tabular-nums',
                strong && 'font-semibold',
                value < 0 && 'text-danger-text',
                className,
            )}
        >
            {formatAmount(value, locale, currency)}
        </bdi>
    );
}

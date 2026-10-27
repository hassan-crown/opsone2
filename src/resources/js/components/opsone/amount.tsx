import { amountParts } from '@/lib/format';
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
    const { sign, number, label } = amountParts(value, locale, currency);
    const figure = (
        <bdi dir="ltr">
            {sign}
            {number}
        </bdi>
    );

    return (
        <span
            className={cn(
                'whitespace-nowrap lining-nums tabular-nums',
                strong && 'font-semibold',
                value < 0 && 'text-danger-text',
                className,
            )}
        >
            {locale === 'ar' ? (
                <>
                    {figure} {label}
                </>
            ) : (
                <>
                    {label} {figure}
                </>
            )}
        </span>
    );
}

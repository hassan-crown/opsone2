import { useT } from '@/lib/i18n';
import { quantityParts } from '@/lib/quantity';
import { cn } from '@/lib/utils';

/** "7.6 م", "3 قطعة": the number LTR-isolated with tabular digits, the unit translated. */
export function Quantity({
    value,
    unit,
    className,
}: {
    value: number | string;
    unit?: string | null;
    className?: string;
}) {
    const t = useT();
    const parts = quantityParts(value, unit, t);

    return (
        <span className={cn('whitespace-nowrap', className)}>
            <bdi dir="ltr" className="tabular-nums">
                {parts.number}
            </bdi>
            {parts.unit && <> {parts.unit}</>}
        </span>
    );
}

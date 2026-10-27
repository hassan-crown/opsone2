import { cn } from '@/lib/utils';

/** Determinate bar sized by logical inline-size, so it fills from the start edge in RTL and LTR. */
export function Progress({
    label,
    value,
    max,
    className,
}: {
    label: string;
    value: number;
    max: number;
    className?: string;
}) {
    const percent =
        max > 0
            ? Math.min(100, Math.max(0, Math.round((value / max) * 100)))
            : 0;
    const tone =
        percent >= 100
            ? 'bg-danger'
            : percent >= 80
              ? 'bg-warning'
              : 'bg-brand';

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={value}
            className={cn(
                'h-2 w-full overflow-hidden rounded-full bg-fill',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full rounded-full transition-[inline-size]',
                    tone,
                )}
                style={{ inlineSize: `${percent}%` }}
            />
        </div>
    );
}

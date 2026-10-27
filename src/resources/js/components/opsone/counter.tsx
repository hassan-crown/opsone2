import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type CounterProps = {
    value: number;
    /** Screen-reader text, e.g. "5 unread". */
    label?: string;
    className?: string;
};

export function Counter({ value, label, className }: CounterProps) {
    return (
        <span
            className={cn(
                'inline-flex h-6 min-w-6 items-center justify-center rounded-full border border-transparent bg-highlight px-1.5 font-latin text-caption font-semibold text-on-highlight contrast:border-highlight-text',
                className,
            )}
        >
            <span aria-hidden="true">
                {value > 99 ? '99+' : formatNumber(value)}
            </span>
            {label && <span className="sr-only">{label}</span>}
        </span>
    );
}

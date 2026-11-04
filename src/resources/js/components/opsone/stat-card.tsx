import { Link } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

export type StatCardProps = {
    label: string;
    value: string;
    icon?: LucideIcon;
    /** e.g. "+12%" — shown LTR-isolated. */
    delta?: string;
    /** Whether the movement is good news (green) or bad (red). */
    positive?: boolean;
    trend?: 'up' | 'down';
    hint?: string;
    href?: ComponentProps<typeof Link>['href'];
    loading?: boolean;
};

/** A signed number with its separators and an optional % ("−12,345.00", "+12%"). */
const NUMBER = /([−+-]?\d[\d,.]*%?)/u;

/**
 * Each number is LTR-isolated with its sign, so "−12,345.00 ر.س" keeps the minus before the digits
 * and the currency after them in Arabic, whatever the value string's own direction is.
 */
function StatValue({ value }: { value: string }) {
    return value.split(NUMBER).map((part, index) =>
        index % 2 === 1 ? (
            <bdi key={index} dir="ltr">
                {part}
            </bdi>
        ) : (
            part
        ),
    );
}

export function StatCard({
    label,
    value,
    icon,
    delta,
    positive = true,
    trend = 'up',
    hint,
    href,
    loading,
}: StatCardProps) {
    const body = (
        <>
            <div className="flex items-start justify-between gap-2">
                <span className="min-w-0 text-label text-weak">{label}</span>
                {icon && <Icon icon={icon} className="shrink-0 text-weak" />}
            </div>
            {loading ? (
                <Skeleton className="my-1 h-7 w-24" />
            ) : (
                <p
                    data-slot="stat-value"
                    className="m-0 text-subtitle font-semibold [overflow-wrap:anywhere] text-strong tabular-nums @[9.5rem]:text-section @[13rem]:text-title"
                >
                    {/* Steps down with the card's width; the space before the currency wraps first and a
                        number breaks only in a card too narrow for it. */}
                    <StatValue value={value} />
                </p>
            )}
            {(delta || hint) && !loading && (
                <p className="m-0 flex flex-wrap items-center gap-2 text-caption">
                    {delta && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 font-semibold',
                                positive
                                    ? 'text-success-text'
                                    : 'text-danger-text',
                            )}
                        >
                            <Icon
                                icon={
                                    trend === 'up' ? TrendingUp : TrendingDown
                                }
                                size="sm"
                            />
                            <bdi dir="ltr">{delta}</bdi>
                        </span>
                    )}
                    {hint && <span className="text-weak">{hint}</span>}
                </p>
            )}
        </>
    );
    const classes =
        '@container flex min-w-0 flex-col gap-1 rounded-lg border border-stroke-weak bg-surface p-5 text-inherit';

    if (href) {
        return (
            <Link
                href={href}
                className={cn(
                    classes,
                    'no-underline transition-colors hover:border-stroke-control active:bg-fill',
                )}
            >
                {body}
            </Link>
        );
    }

    return <div className={classes}>{body}</div>;
}

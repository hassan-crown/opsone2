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
            <div className="flex items-center justify-between gap-2">
                <span className="text-label text-weak">{label}</span>
                {icon && <Icon icon={icon} className="text-weak" />}
            </div>
            {loading ? (
                <Skeleton className="my-1 h-7 w-24" />
            ) : (
                <p className="m-0 text-title text-strong tabular-nums">
                    {value}
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
        'flex min-w-0 flex-col gap-1 rounded-lg border border-stroke-weak bg-surface p-5 text-inherit';

    if (href) {
        return (
            <Link
                href={href}
                className={cn(
                    classes,
                    'no-underline hover:border-stroke-control',
                )}
            >
                {body}
            </Link>
        );
    }

    return <div className={classes}>{body}</div>;
}

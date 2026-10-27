import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import { cn } from '@/lib/utils';

export type EmptyStateProps = {
    icon: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    compact?: boolean;
    tone?: 'neutral' | 'danger';
};

export function EmptyState({
    icon,
    title,
    description,
    action,
    compact,
    tone = 'neutral',
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-2 px-4 text-center',
                compact ? 'py-6' : 'py-10',
            )}
        >
            <span
                className={cn(
                    'mb-2 inline-flex size-12 items-center justify-center rounded-full',
                    tone === 'danger'
                        ? 'bg-danger-bg text-danger-text'
                        : 'bg-fill text-weak',
                )}
            >
                <Icon icon={icon} />
            </span>
            <h3 className="text-subtitle text-strong">{title}</h3>
            {description && (
                <p className="max-w-[44ch] text-body text-weak">
                    {description}
                </p>
            )}
            {action && (
                <div className="mt-3 flex flex-wrap justify-center gap-2">
                    {action}
                </div>
            )}
        </div>
    );
}

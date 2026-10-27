import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type PageHeaderProps = {
    title: string;
    description?: string;
    actions?: ReactNode;
    /** A "back" link rendered above the title. */
    back?: ReactNode;
    className?: string;
};

export function PageHeader({
    title,
    description,
    actions,
    back,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'mb-6 flex flex-wrap items-end justify-between gap-4',
                className,
            )}
        >
            <div className="min-w-0 flex-[1_1_280px]">
                {back && <div className="mb-2">{back}</div>}
                <h1 className="text-title text-strong">{title}</h1>
                {description && (
                    <p className="mt-1 max-w-reading text-body text-weak">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap gap-2 max-md:w-full max-md:*:flex-1">
                    {actions}
                </div>
            )}
        </div>
    );
}

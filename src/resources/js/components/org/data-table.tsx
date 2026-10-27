import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Scrolls horizontally (focusable) when the table is wider than its container. */
export function TableWrap({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            tabIndex={0}
            className={cn(
                'w-full overflow-x-auto rounded-md focus-visible:-outline-offset-2',
                className,
            )}
            {...props}
        />
    );
}

export function Table({
    caption,
    className,
    children,
    ...props
}: ComponentProps<'table'> & { caption: string }) {
    return (
        <table
            className={cn(
                'w-full min-w-xl border-collapse text-start text-body',
                className,
            )}
            {...props}
        >
            <caption className="sr-only">{caption}</caption>
            {children}
        </table>
    );
}

export function Th({ className, ...props }: ComponentProps<'th'>) {
    return (
        <th
            scope="col"
            className={cn(
                'border-b border-stroke-weak bg-fill px-4 py-2.5 text-start text-label whitespace-nowrap text-weak',
                className,
            )}
            {...props}
        />
    );
}

export function Td({ className, ...props }: ComponentProps<'td'>) {
    return (
        <td
            className={cn(
                'border-b border-stroke-weak px-4 py-3 align-middle text-strong',
                className,
            )}
            {...props}
        />
    );
}

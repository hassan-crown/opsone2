import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Save/cancel bar for long forms: sticks to the bottom of the viewport while the form scrolls,
 * clears the home indicator, and sits back in the flow at the end of the form. Put it last inside the `<form>`.
 */
export function StickyActions({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            data-slot="sticky-actions"
            className={cn(
                'sticky bottom-0 z-[5] mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-stroke-weak bg-surface p-3 pb-[max(--spacing(3),env(safe-area-inset-bottom))] max-md:*:flex-1',
                className,
            )}
        >
            {children}
        </div>
    );
}

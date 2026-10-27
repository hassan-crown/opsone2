import {
    ArrowLeft,
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    LogOut,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon, LucideProps } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Icons that point somewhere and must mirror in RTL. Everything else (logo, numbers, clocks) stays. */
const DIRECTIONAL = new Set<LucideIcon>([
    ArrowLeft,
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    LogOut,
    TrendingDown,
    TrendingUp,
]);

const SIZES = { md: 20, sm: 16 } as const;

export type IconProps = Omit<LucideProps, 'size' | 'ref'> & {
    icon: LucideIcon;
    size?: keyof typeof SIZES;
    /** Accessible name. Without it the icon is decorative (`aria-hidden`). */
    label?: string;
};

export function Icon({
    icon: Component,
    size = 'md',
    label,
    className,
    ...rest
}: IconProps) {
    const a11y = label
        ? ({ role: 'img', 'aria-label': label } as const)
        : ({ 'aria-hidden': true } as const);

    return (
        <Component
            size={SIZES[size]}
            strokeWidth={1.75}
            focusable="false"
            className={cn(
                'shrink-0',
                DIRECTIONAL.has(Component) && 'rtl:-scale-x-100',
                className,
            )}
            {...a11y}
            {...rest}
        />
    );
}

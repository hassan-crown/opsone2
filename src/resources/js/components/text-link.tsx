import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'rounded-xs text-link underline underline-offset-4 hover:text-brand-hover',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}

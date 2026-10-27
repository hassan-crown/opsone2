import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export type RefProps = {
    children: string;
    href?: ComponentProps<typeof Link>['href'];
    className?: string;
};

/** Order/ticket/invoice reference: LTR-isolated, monospace, optionally a link. */
export function Ref({ children, href, className }: RefProps) {
    const classes = cn('font-mono text-ref whitespace-nowrap', className);

    if (href) {
        return (
            <Link
                href={href}
                className={cn(
                    classes,
                    'rounded-xs text-link underline-offset-4 hover:underline',
                )}
            >
                <bdi dir="ltr">{children}</bdi>
            </Link>
        );
    }

    return (
        <bdi dir="ltr" className={classes}>
            {children}
        </bdi>
    );
}

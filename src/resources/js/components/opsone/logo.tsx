import type { SVGProps } from 'react';
import { LOGO_SHAPES, LOGO_VIEWBOX } from '@/components/opsone/logo-data';
import { cn } from '@/lib/utils';

const ROLE_CLASS = {
    accent: 'fill-logo-accent',
    tile: 'fill-logo-tile',
    word: 'fill-logo-word',
} as const;

export type LogoProps = Omit<SVGProps<SVGSVGElement>, 'height' | 'width'> & {
    /** `full` = mark + wordmark, `mark` = the symbol only (use ≥ 24px). */
    variant?: 'full' | 'mark';
    height?: number;
    title?: string;
};

/**
 * The original OpsOne logo (geometry copied from the design system; never redrawn).
 * Colours switch with the theme; it is not mirrored in RTL and not translated.
 */
export function Logo({
    variant = 'full',
    height = 32,
    title = 'OpsOne',
    className,
    ...rest
}: LogoProps) {
    const mark = variant === 'mark';
    const viewBoxWidth = mark ? LOGO_VIEWBOX.mark : LOGO_VIEWBOX.full;
    const shapes = mark
        ? LOGO_SHAPES.filter((shape) => shape.role !== 'word')
        : LOGO_SHAPES;

    return (
        <svg
            viewBox={`0 0 ${viewBoxWidth} ${LOGO_VIEWBOX.height}`}
            height={height}
            width={(height * viewBoxWidth) / LOGO_VIEWBOX.height}
            role="img"
            aria-label={title}
            className={cn('block shrink-0', className)}
            {...rest}
        >
            {shapes.map(({ tag: Tag, attrs, role }, index) => (
                <Tag key={index} {...attrs} className={ROLE_CLASS[role]} />
            ))}
        </svg>
    );
}

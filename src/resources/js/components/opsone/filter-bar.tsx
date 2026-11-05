import { SlidersHorizontal } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type FilterChip = {
    value: string;
    label: string;
    /** Shown in a badge after the label. Leave undefined to hide (0 is shown). */
    count?: number;
};

/**
 * One row of mutually exclusive quick filters (status tabs). The single active style is
 * `bg-brand text-on-brand`; the count always follows the label. Scrolls sideways on a phone instead of wrapping.
 */
export function FilterChips({
    label,
    options,
    value,
    onChange,
    className,
}: {
    /** Accessible name of the group, e.g. the page title. */
    label: string;
    options: FilterChip[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            data-slot="filter-chips"
            className={cn(
                '-mx-4 flex snap-x [scrollbar-width:none] gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:overflow-visible md:px-0',
                className,
            )}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(option.value)}
                        data-test={`chip-${option.value || 'all'}`}
                        className={cn(
                            'inline-flex min-h-11 shrink-0 cursor-pointer snap-start items-center gap-2 rounded-full border px-4 text-control whitespace-nowrap transition-colors md:min-h-9',
                            active
                                ? 'border-transparent bg-brand font-semibold text-on-brand contrast:border-current'
                                : 'border-stroke-control bg-surface text-strong hover:bg-hover active:bg-stroke-weak',
                        )}
                    >
                        {option.label}
                        {option.count !== undefined && (
                            <Badge
                                variant="neutral"
                                className={cn(
                                    'min-h-5 rounded-full px-1.5 py-0 text-caption tabular-nums',
                                    active && 'bg-on-brand/20 text-on-brand',
                                )}
                            >
                                <bdi dir="ltr">
                                    {formatNumber(option.count)}
                                </bdi>
                            </Badge>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

const MOBILE = '(max-width: 47.999rem)';

function useIsMobile(): boolean {
    return useSyncExternalStore(
        (notify) => {
            const query = window.matchMedia(MOBILE);
            query.addEventListener('change', notify);

            return () => query.removeEventListener('change', notify);
        },
        () => window.matchMedia(MOBILE).matches,
        () => false,
    );
}

/**
 * GET filter form shared by list pages. Quick `chips` stay visible; the detailed `children` fields sit
 * in a panel on md+ and fold behind «تصفية (n)» into a bottom sheet on a phone so the list starts on the first screen.
 * The page owns the values and the request: `onSubmit` applies, `onClear` resets.
 */
export function FilterBar({
    chips,
    children,
    activeCount = 0,
    onSubmit,
    onClear,
    className,
}: {
    chips?: ReactNode;
    /** The detailed fields (search, selects). Laid out in a responsive grid. */
    children?: ReactNode;
    /** Detailed filters currently applied (not counting the chips). */
    activeCount?: number;
    onSubmit: () => void;
    onClear?: () => void;
    className?: string;
}) {
    const t = useT();
    const mobile = useIsMobile();
    const [open, setOpen] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
        setOpen(false);
    };

    const actions = (
        <div className="flex items-end gap-2 max-md:*:flex-1">
            <Button type="submit">{t('ui.filter.apply')}</Button>
            {onClear && (
                <Button
                    type="button"
                    variant="ghost"
                    disabled={activeCount === 0}
                    onClick={() => {
                        onClear();
                        setOpen(false);
                    }}
                >
                    {t('ui.filter.clear')}
                </Button>
            )}
        </div>
    );

    const fields = (
        <form
            role="search"
            onSubmit={submit}
            data-test="filter-bar"
            className={cn(
                'grid gap-3',
                !mobile &&
                    'rounded-lg border border-stroke-weak bg-card p-4 md:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]',
            )}
        >
            {children}
            {actions}
        </form>
    );

    return (
        <div className={cn('mb-4 flex flex-col gap-3', className)}>
            {(chips || (mobile && children)) && (
                <div className="flex items-center gap-2">
                    {chips && <div className="min-w-0 flex-1">{chips}</div>}
                    {mobile && children && (
                        <Button
                            type="button"
                            variant="outline"
                            className="ms-auto shrink-0"
                            onClick={() => setOpen(true)}
                            data-test="filter-open"
                        >
                            <Icon icon={SlidersHorizontal} />
                            {t('ui.filter.open')}
                            {activeCount > 0 && (
                                <Badge
                                    variant="brand"
                                    className="min-h-5 rounded-full px-1.5 py-0 text-caption"
                                >
                                    <bdi dir="ltr">{activeCount}</bdi>
                                </Badge>
                            )}
                        </Button>
                    )}
                </div>
            )}
            {children &&
                (mobile ? (
                    <Sheet open={open} onOpenChange={setOpen}>
                        <SheetContent
                            side="bottom"
                            className="max-h-[85dvh] overflow-y-auto p-4 pb-[max(--spacing(4),env(safe-area-inset-bottom))]"
                        >
                            <SheetTitle>{t('ui.filter.title')}</SheetTitle>
                            <SheetDescription className="sr-only">
                                {t('ui.filter.title')}
                            </SheetDescription>
                            <div className="mt-4">{fields}</div>
                        </SheetContent>
                    </Sheet>
                ) : (
                    fields
                ))}
        </div>
    );
}

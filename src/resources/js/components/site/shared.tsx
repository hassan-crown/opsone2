import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageState, SiteTabs } from '@/types/site';

const TAB_HREF = {
    pages: '/site-admin/pages',
    theme: '/site-admin/theme',
    domain: '/site-admin/domain',
} as const;

/** Section tabs of the "Site" area (real links); only the ones the viewer may open are listed. */
export function SiteNav({
    active,
    tabs,
}: {
    active: keyof SiteTabs;
    tabs: SiteTabs;
}) {
    const t = useT();
    const items = (['pages', 'theme', 'domain'] as const).filter(
        (key) => tabs[key],
    );

    return (
        <nav
            aria-label={t('site.tabs')}
            className="mb-4 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b border-stroke-weak"
        >
            {items.map((key) => (
                <Link
                    key={key}
                    href={TAB_HREF[key]}
                    aria-current={key === active ? 'page' : undefined}
                    className={cn(
                        'relative inline-flex min-h-11 shrink-0 items-center rounded-t-md px-4 text-control text-weak hover:bg-hover hover:text-strong focus-visible:-outline-offset-2',
                        key === active &&
                            'font-semibold text-strong after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-xs after:bg-brand',
                    )}
                >
                    {t(`site.tab_${key}` as TranslationKey)}
                </Link>
            ))}
        </nav>
    );
}

const VARIANT = {
    draft: 'warning',
    published: 'success',
    published_with_draft: 'info',
    archived: 'neutral',
    system: 'neutral',
} as const;

export function PageStateBadge({ state }: { state: PageState }) {
    const t = useT();

    return (
        <Badge variant={VARIANT[state]}>
            {t(`site.state.${state}` as TranslationKey)}
        </Badge>
    );
}

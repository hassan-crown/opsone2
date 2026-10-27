import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { Meta } from './types';

/** Server-side pagination (NFR-13): changes `page` and keeps the active filters. */
export function Pager({
    meta,
    url,
    query,
}: {
    meta: Meta;
    url: string;
    query: Record<string, string>;
}) {
    const t = useT();

    if (meta.last_page <= 1) {
        return null;
    }

    const go = (page: number) =>
        router.get(
            url,
            { ...query, page },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <nav
            aria-label={t('customers.page.nav')}
            className="flex flex-wrap items-center justify-between gap-3 border-t border-stroke-weak px-4 py-3"
        >
            <span className="text-caption text-weak" data-test="pager-summary">
                {t('customers.page.summary', {
                    page: meta.page,
                    last: meta.last_page,
                    total: meta.total,
                })}
            </span>
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    disabled={meta.page <= 1}
                    onClick={() => go(meta.page - 1)}
                >
                    <Icon icon={ChevronLeft} className="rtl:rotate-180" />
                    {t('customers.page.prev')}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    disabled={meta.page >= meta.last_page}
                    onClick={() => go(meta.page + 1)}
                >
                    {t('customers.page.next')}
                    <Icon icon={ChevronRight} className="rtl:rotate-180" />
                </Button>
            </div>
        </nav>
    );
}

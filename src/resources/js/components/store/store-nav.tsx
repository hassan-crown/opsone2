import { Link, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const TABS: { key: 'links' | 'coupons' | 'settings'; href: string; label: TranslationKey }[] = [
    { key: 'links', href: '/store-admin/links', label: 'links.title' },
    { key: 'coupons', href: '/store-admin/coupons', label: 'coupons.title' },
    { key: 'settings', href: '/store-admin/settings', label: 'store.settings.title' },
];

/** Tabs of the staff store area; a tab is a plain link, the server authorizes every page. */
export function StoreNav({ active }: { active: 'links' | 'coupons' | 'settings' }) {
    const t = useT();
    const { url } = usePage();

    return (
        <nav aria-label={t('store.nav')} className="mb-6 flex flex-wrap gap-2 border-b border-stroke-weak pb-2">
            {TABS.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={active === tab.key || url.startsWith(tab.href) ? 'page' : undefined}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-control hover:bg-hover',
                        active === tab.key && 'bg-brand-subtle text-brand-subtle-text',
                    )}
                >
                    {t(tab.label)}
                </Link>
            ))}
        </nav>
    );
}

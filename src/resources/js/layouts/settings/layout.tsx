import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { PageHeader } from '@/components/opsone/page-header';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: (Omit<NavItem, 'title'> & { label: TranslationKey })[] =
    [
        { label: 'settings.profile', href: edit() },
        { label: 'settings.security', href: editSecurity() },
        { label: 'appearance.title', href: editAppearance() },
    ];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const t = useT();
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div>
            <PageHeader
                title={t('settings.title')}
                description={t('settings.description')}
            />

            <div className="flex flex-col lg:flex-row lg:gap-12">
                <aside className="w-full max-w-xl lg:w-52">
                    <nav
                        className="flex flex-col gap-1"
                        aria-label={t('settings.nav_label')}
                    >
                        {sidebarNavItems.map((item) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={toUrl(item.href)}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex min-h-11 items-center rounded-md px-3 text-control text-weak hover:bg-hover hover:text-strong focus-visible:-outline-offset-2',
                                        active &&
                                            'bg-brand-subtle font-semibold text-brand-subtle-text hover:bg-brand-subtle hover:text-brand-subtle-text',
                                    )}
                                >
                                    {t(item.label)}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="min-w-0 flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}

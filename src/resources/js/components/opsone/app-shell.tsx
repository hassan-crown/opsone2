import { Link, usePage } from '@inertiajs/react';
import {
    ChevronsUpDown,
    CircleHelp,
    Menu as MenuIcon,
    Settings,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { Breadcrumbs } from '@/components/opsone/breadcrumbs';
import { HelpLink } from '@/components/opsone/help-link';
import { Icon } from '@/components/opsone/icon';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { LicenseBanner } from '@/components/opsone/license-banner';
import { Logo } from '@/components/opsone/logo';
import { NotificationsBell } from '@/components/opsone/notifications-bell';
import { ShellSearch } from '@/components/opsone/shell-search';
import { ThemeSwitch } from '@/components/opsone/theme-switch';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { mainNav } from '@/lib/navigation';
import type { LocalizedNavItem } from '@/lib/navigation';
import { cn, toUrl } from '@/lib/utils';
import { home as settingsHome } from '@/routes/settings';
import type { BreadcrumbItem } from '@/types';

/**
 * Sidebar sections, by the nav item's label key (navigation.ts stays the single list of items and access).
 * An item not listed here lands in the last section, so a new page is never hidden.
 */
const NAV_GROUPS: { label?: TranslationKey; items: string[] }[] = [
    { items: ['nav.dashboard', 'executive.nav', 'tasks.nav'] },
    {
        label: 'nav.group.sales',
        items: [
            'interests.nav',
            'customers.nav',
            'orders.nav',
            'calendar.nav',
            'appointment_requests.nav',
            'field_sales.nav',
            'field_sales.team.nav',
            'field_sales.nav_app',
            'store.nav',
        ],
    },
    {
        label: 'nav.group.execution',
        items: [
            'workshops.nav',
            'delivery.nav',
            'returns.nav',
            'tickets.nav_staff',
        ],
    },
    {
        label: 'nav.group.finance',
        items: ['finance.nav', 'commissions.nav', 'reports.nav.reports'],
    },
    {
        label: 'nav.group.supply',
        items: ['catalog.nav', 'stock.nav', 'procurement.nav'],
    },
    {
        label: 'nav.group.admin',
        items: [
            'org.nav.team',
            'org.nav.branches',
            'site.nav',
            'flow.nav',
            'audit.nav',
        ],
    },
];

function groupNav(items: LocalizedNavItem[]) {
    const placed = new Set(NAV_GROUPS.flatMap((group) => group.items));
    const sections = NAV_GROUPS.map((group, index) => ({
        key: group.label ?? `group-${index}`,
        label: group.label,
        items: group.items.flatMap((label) =>
            items.filter((item) => item.label === label),
        ),
    }));

    sections[sections.length - 1].items.push(
        ...items.filter((item) => !placed.has(item.label)),
    );

    return sections.filter((section) => section.items.length > 0);
}

function NavLinks({ onNavigate }: { onNavigate?: () => void }) {
    const t = useT();
    const { isCurrentOrParentUrl, currentUrl } = useCurrentUrl();
    const { orgNav } = usePage().props;

    const item = (
        key: string,
        href: LocalizedNavItem['href'],
        label: string,
        IconComponent?: LocalizedNavItem['icon'],
        className?: string,
    ) => {
        const active = isCurrentOrParentUrl(href);

        return (
            <li key={key} className={className}>
                <Link
                    href={href}
                    prefetch
                    aria-current={active ? 'page' : undefined}
                    onClick={onNavigate}
                    className={cn(
                        'flex min-h-11 items-center gap-3 rounded-md px-3 text-control text-weak transition-colors hover:bg-hover hover:text-strong focus-visible:-outline-offset-2 active:bg-fill',
                        active &&
                            'bg-brand-subtle font-semibold text-brand-subtle-text hover:bg-brand-subtle hover:text-brand-subtle-text contrast:shadow-[inset_0_0_0_1px_var(--brand)]',
                    )}
                >
                    {IconComponent && <Icon icon={IconComponent} />}
                    <span className="min-w-0 flex-1 truncate">{label}</span>
                </Link>
            </li>
        );
    };

    const sections = groupNav(
        mainNav.filter((nav) => !nav.access || orgNav?.[nav.access]),
    );

    return (
        <>
            <nav
                aria-label={t('ui.main_nav')}
                className="-mx-1 min-h-0 flex-1 overflow-y-auto overscroll-contain px-1"
            >
                {sections.map((section) => (
                    <div key={section.key} className="mt-4 first:mt-0">
                        {section.label && (
                            <h2 className="m-0 px-3 pb-1 text-caption font-normal text-weak">
                                {t(section.label)}
                            </h2>
                        )}
                        <ul className="m-0 flex list-none flex-col gap-0.5 p-0">
                            {section.items.map((nav) =>
                                item(
                                    toUrl(nav.href),
                                    nav.href,
                                    t(nav.label),
                                    nav.icon,
                                ),
                            )}
                        </ul>
                    </div>
                ))}
            </nav>
            <div className="mt-auto shrink-0 border-t border-stroke-weak pt-4">
                <ul className="m-0 flex list-none flex-col gap-1 p-0">
                    {/* On a phone the header has no room for «?»: the drawer carries it instead. */}
                    {onNavigate &&
                        item(
                            'help',
                            HelpController.context.url({
                                query: { path: currentUrl },
                            }),
                            t('help.contextual'),
                            CircleHelp,
                            'sm:hidden',
                        )}
                    {item(
                        'settings',
                        settingsHome(),
                        t('nav.settings'),
                        Settings,
                    )}
                </ul>
            </div>
        </>
    );
}

function Brand({ height = 28 }: { height?: number }) {
    return (
        <Link href="/" className="inline-flex rounded-md" aria-label="OpsOne">
            <Logo height={height} />
        </Link>
    );
}

function UserMenu() {
    const { auth } = usePage().props;
    const t = useT();

    if (!auth.user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    data-test="sidebar-menu-button"
                    aria-label={t('ui.user_menu')}
                    className="flex min-h-11 items-center gap-2 rounded-md px-1 text-start hover:bg-hover"
                >
                    <UserInfo user={auth.user} hideTextOnMobile />
                    <Icon
                        icon={ChevronsUpDown}
                        size="sm"
                        className="text-weak max-md:hidden"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-64">
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function AppShell({
    children,
    breadcrumbs = [],
}: {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}) {
    const t = useT();
    const [drawerOpen, setDrawerOpen] = useState(false);

    return (
        <div className="flex min-h-dvh bg-background">
            <a
                href="#o1-main"
                className="absolute inset-s-4 -top-24 z-50 rounded-md bg-brand px-4 py-2 font-medium text-on-brand no-underline focus:top-2"
            >
                {t('ui.skip')}
            </a>

            <aside className="sticky top-0 hidden h-dvh w-66 shrink-0 flex-col border-e border-stroke-weak bg-surface px-3 py-5 lg:flex">
                <div className="flex min-h-10 shrink-0 items-center px-3 pb-5">
                    <Brand />
                </div>
                <NavLinks />
            </aside>

            <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
                <SheetContent
                    side="start"
                    className="flex flex-col px-3 py-4 pb-[max(--spacing(4),env(safe-area-inset-bottom))] lg:hidden"
                    aria-describedby={undefined}
                >
                    <SheetTitle className="sr-only">{t('ui.menu')}</SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('ui.main_nav')}
                    </SheetDescription>
                    <div className="flex min-h-10 shrink-0 items-center px-3 pb-4">
                        <Brand />
                    </div>
                    <NavLinks onNavigate={() => setDrawerOpen(false)} />
                </SheetContent>
            </Sheet>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-10 box-border flex min-h-16 items-center gap-2 border-b border-stroke-weak bg-surface px-2 md:px-6 lg:px-6">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="text-strong lg:hidden"
                        aria-label={t('ui.open_menu')}
                        onClick={() => setDrawerOpen(true)}
                    >
                        <Icon icon={MenuIcon} />
                    </Button>
                    {/* Below sm the logo lives in the drawer so every header control keeps a 44px target. */}
                    <div className="max-sm:hidden lg:hidden">
                        <Brand height={24} />
                    </div>
                    <div className="min-w-0 max-lg:hidden">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                    <div className="ms-auto flex min-w-0 flex-1 items-center justify-end gap-1 [&_[data-slot=button]]:shrink-0 [&>button]:shrink-0">
                        <ShellSearch />
                        <div className="contents max-sm:hidden">
                            <HelpLink />
                        </div>
                        <NotificationsBell />
                        <LanguageSwitch />
                        <ThemeSwitch />
                        <UserMenu />
                    </div>
                </header>
                <main
                    id="o1-main"
                    tabIndex={-1}
                    className="box-border w-full max-w-[calc(var(--container-content)+3rem)] flex-1 p-4 outline-none md:p-6"
                >
                    <LicenseBanner />
                    {children}
                </main>
            </div>
        </div>
    );
}

import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    CircleHelp,
    FileText,
    House,
    LifeBuoy,
    Languages,
    Package,
    Settings,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useLocale, useT } from '@/lib/i18n';
import type { Locale, TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    home,
    locale as localeRoute,
    notifications,
    settings,
} from '@/routes/customer';
import { index as documentsIndex } from '@/routes/customer/documents';
import { index as helpIndex } from '@/routes/customer/help';
import { index as ordersIndex } from '@/routes/customer/orders';
import { index as ticketsIndex } from '@/routes/customer/tickets';
import type { PortalShared } from '@/types/portal';

const NAMES: Record<Locale, string> = { ar: 'العربية', en: 'English' };
const SHORT: Record<Locale, string> = { ar: 'ع', en: 'EN' };

type NavItem = { label: TranslationKey; href: string; icon: LucideIcon };

export function usePortal(): PortalShared {
    return usePage().props.portal as PortalShared;
}

/**
 * The customer workspace frame (WEB-09): the facility's name, language, and for a signed-in customer the four
 * workspace sections (a bottom bar on phones, a row of tabs from md up) with updates and settings (which holds
 * sign-out) as header icons. No staff navigation or OpsOne admin chrome; a small «by OpsOne» credit in the footer
 * (Q-49 a).
 *
 * `--portal-bar` is the height the bottom bar covers (safe area included, zero from md up): page content pads by it
 * and anything sticky to the bottom of a page sits above it.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const { url } = usePage();
    const { facility, customer } = usePortal();
    const { locale, locales } = useLocale();
    const target = locales.find((candidate) => candidate !== locale) ?? locale;
    useFlashToast();

    const nav: NavItem[] = [
        { label: 'portal.nav.overview', href: home().url, icon: House },
        { label: 'portal.nav.orders', href: ordersIndex().url, icon: Package },
        {
            label: 'portal.nav.documents',
            href: documentsIndex().url,
            icon: FileText,
        },
        {
            label: 'tickets.nav',
            href: ticketsIndex().url,
            icon: LifeBuoy,
        },
    ];
    const tools: NavItem[] = [
        { label: 'portal.nav.updates', href: notifications().url, icon: Bell },
        { label: 'portal.nav.help', href: helpIndex().url, icon: CircleHelp },
        { label: 'portal.nav.settings', href: settings().url, icon: Settings },
    ];
    const path = url.split('?')[0];
    const active = (href: string) => {
        const target = new URL(href, 'http://x').pathname;

        return target === home().url
            ? path === target
            : path === target || path.startsWith(`${target}/`);
    };

    return (
        <div
            className={cn(
                'flex min-h-dvh flex-col bg-background [--portal-bar:0px]',
                customer &&
                    'max-md:[--portal-bar:calc(3.5rem+env(safe-area-inset-bottom))]',
            )}
        >
            <a
                href="#portal-main"
                className="sr-only focus:not-sr-only focus:absolute focus:start-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-surface focus:p-2"
            >
                {t('ui.skip')}
            </a>
            <header className="sticky top-0 z-20 border-b border-stroke-weak bg-surface">
                <div className="mx-auto flex min-h-14 max-w-3xl items-center gap-2 px-4">
                    <Link
                        href={customer ? home().url : '/'}
                        className="me-auto line-clamp-2 min-w-0 py-1 text-subtitle break-words text-strong max-sm:text-label"
                    >
                        {facility ?? t('portal.title')}
                    </Link>
                    <Button
                        type="button"
                        variant="ghost"
                        lang={target}
                        aria-label={t('ui.switch_language', {
                            language: NAMES[target],
                        })}
                        onClick={() =>
                            router.put(
                                localeRoute().url,
                                { locale: target },
                                { preserveScroll: true },
                            )
                        }
                        className="shrink-0 px-3 text-strong"
                    >
                        <Icon icon={Languages} />
                        <span className="max-sm:hidden">{NAMES[target]}</span>
                        <span className="sm:hidden" aria-hidden="true">
                            {SHORT[target]}
                        </span>
                    </Button>
                    {customer &&
                        tools.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                aria-label={t(item.label)}
                                title={t(item.label)}
                                aria-current={
                                    active(item.href) ? 'page' : undefined
                                }
                                className={cn(
                                    'inline-flex size-11 shrink-0 items-center justify-center rounded-md hover:bg-hover',
                                    active(item.href)
                                        ? 'bg-brand-subtle text-brand-subtle-text'
                                        : 'text-strong',
                                )}
                            >
                                <Icon icon={item.icon} />
                            </Link>
                        ))}
                </div>
                {customer && (
                    <nav
                        aria-label={t('ui.main_nav')}
                        className="mx-auto hidden max-w-3xl gap-1 px-4 md:flex"
                    >
                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                aria-current={
                                    active(item.href) ? 'page' : undefined
                                }
                                className={cn(
                                    'border-b-2 px-3 py-2 text-label text-weak hover:text-strong',
                                    active(item.href)
                                        ? 'border-brand text-strong'
                                        : 'border-transparent',
                                )}
                            >
                                {t(item.label)}
                            </Link>
                        ))}
                    </nav>
                )}
            </header>

            <main
                id="portal-main"
                className="mx-auto w-full max-w-3xl flex-1 px-4 py-6"
            >
                {children}
            </main>

            <footer className="mx-auto w-full max-w-3xl px-4 pb-[calc(var(--portal-bar)+1.5rem)] text-center text-caption text-weak">
                {t('portal.powered_by')}
            </footer>

            {customer && (
                <nav
                    aria-label={t('ui.main_nav')}
                    className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-4 border-t border-stroke-weak bg-surface pb-[env(safe-area-inset-bottom)] md:hidden"
                >
                    {nav.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={
                                active(item.href) ? 'page' : undefined
                            }
                            className={cn(
                                'flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 px-1 text-caption select-none [-webkit-tap-highlight-color:transparent] [-webkit-touch-callout:none]',
                                active(item.href)
                                    ? 'font-semibold text-brand'
                                    : 'text-weak active:text-strong',
                            )}
                        >
                            <Icon icon={item.icon} />
                            <span className="max-w-full truncate">
                                {t(item.label)}
                            </span>
                        </Link>
                    ))}
                </nav>
            )}
        </div>
    );
}

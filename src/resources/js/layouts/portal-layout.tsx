import { Form, Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    FileText,
    House,
    Languages,
    LogOut,
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
    logout,
    notifications,
    settings,
} from '@/routes/customer';
import { index as documentsIndex } from '@/routes/customer/documents';
import { index as ordersIndex } from '@/routes/customer/orders';
import type { PortalShared } from '@/types/portal';

const NAMES: Record<Locale, string> = { ar: 'العربية', en: 'English' };

type NavItem = { label: TranslationKey; href: string; icon: LucideIcon };

export function usePortal(): PortalShared {
    return usePage().props.portal as PortalShared;
}

/**
 * The customer workspace frame (WEB-09): the facility's name, language, and for a signed-in customer the five
 * workspace sections (a bottom bar on phones, a row of tabs from md up). No staff navigation or OpsOne admin chrome;
 * a small «by OpsOne» credit in the footer (Q-49 a).
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
        { label: 'portal.nav.updates', href: notifications().url, icon: Bell },
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
        <div className="flex min-h-dvh flex-col bg-background">
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
                        className="me-auto truncate text-subtitle text-strong"
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
                        className="px-3 text-strong"
                    >
                        <Icon icon={Languages} />
                        <span>{NAMES[target]}</span>
                    </Button>
                    {customer && (
                        <Form {...logout.form()}>
                            <Button
                                type="submit"
                                variant="ghost"
                                aria-label={t('portal.sign_out')}
                                className="px-3"
                            >
                                <Icon icon={LogOut} />
                                <span className="max-sm:sr-only">
                                    {t('portal.sign_out')}
                                </span>
                            </Button>
                        </Form>
                    )}
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
                className={cn(
                    'mx-auto w-full max-w-3xl flex-1 px-4 py-6',
                    customer && 'max-md:pb-28',
                )}
            >
                {children}
            </main>

            <footer className="mx-auto w-full max-w-3xl px-4 pb-6 text-center text-caption text-weak max-md:pb-24">
                {t('portal.powered_by')}
            </footer>

            {customer && (
                <nav
                    aria-label={t('ui.main_nav')}
                    className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-5 border-t border-stroke-weak bg-surface pb-[env(safe-area-inset-bottom)] md:hidden"
                >
                    {nav.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={
                                active(item.href) ? 'page' : undefined
                            }
                            className={cn(
                                'flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-caption',
                                active(item.href)
                                    ? 'text-brand'
                                    : 'text-weak hover:text-strong',
                            )}
                        >
                            <Icon icon={item.icon} />
                            <span className="truncate">{t(item.label)}</span>
                        </Link>
                    ))}
                </nav>
            )}
        </div>
    );
}

import { Link, usePage } from '@inertiajs/react';
import { CircleHelp, CloudOff, LayoutDashboard, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import { Icon } from '@/components/opsone/icon';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { Logo } from '@/components/opsone/logo';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { flush } from '@/lib/field-queue';
import { useT } from '@/lib/i18n';
import { dashboard, logout } from '@/routes';

/** Online/offline as the browser sees it. */
export function useOnline(): boolean {
    const [online, setOnline] = useState(
        typeof navigator === 'undefined' ? true : navigator.onLine,
    );
    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}

/**
 * The installation field app frame (ADR-0036): a single-column phone layout with OpsOne's identity only — never
 * the tenant's brand. Registers the service worker (scope /field) and sends queued photos when the connection
 * returns.
 */
export default function FieldLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const online = useOnline();
    const { auth } = usePage().props;
    useFlashToast();

    useEffect(() => {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker
                .register('/field-sw.js', { scope: '/field' })
                .catch(() => undefined);
        }
    }, []);

    useEffect(() => {
        if (online) {
            void flush().catch(() => undefined);
        }
    }, [online]);

    const signOut = () => {
        navigator.serviceWorker?.controller?.postMessage({ type: 'forget' });
    };

    return (
        <div className="mx-auto flex min-h-dvh w-full max-w-xl flex-col bg-background">
            <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-stroke-weak bg-surface/95 px-4 py-2 backdrop-blur">
                <Link
                    href={FieldController.index.url()}
                    className="flex items-center gap-2 no-underline"
                    aria-label={t('field.title')}
                >
                    <Logo variant="mark" height={28} />
                    <span className="text-label text-strong">
                        {t('field.title')}
                    </span>
                </Link>
                <div className="ms-auto flex items-center gap-1">
                    {!online && (
                        <span
                            className="inline-flex items-center gap-1 rounded-sm bg-warning-bg px-2 py-1 text-caption text-warning-text"
                            role="status"
                            data-test="offline"
                        >
                            <Icon icon={CloudOff} size="sm" />
                            {t('field.offline')}
                        </span>
                    )}
                    <Button
                        asChild
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('field.install.nav')}
                    >
                        <Link href={FieldController.install.url()}>
                            <Icon icon={CircleHelp} />
                        </Link>
                    </Button>
                </div>
            </header>
            <main className="flex-1 px-4 pt-4 pb-10">{children}</main>
            <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-stroke-weak px-4 py-3 text-caption text-weak">
                <span>{auth.user.name}</span>
                <div className="flex items-center gap-1">
                    <LanguageSwitch />
                    <Button asChild variant="ghost" size="sm">
                        <Link href={dashboard()}>
                            <Icon icon={LayoutDashboard} size="sm" />
                            {t('field.full_app')}
                        </Link>
                    </Button>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={logout()} as="button" onClick={signOut}>
                            <Icon icon={LogOut} size="sm" />
                            {t('field.sign_out')}
                        </Link>
                    </Button>
                </div>
            </footer>
        </div>
    );
}

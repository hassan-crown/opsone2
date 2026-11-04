import { Link, router, usePage } from '@inertiajs/react';
import {
    CircleHelp,
    CloudOff,
    History,
    LayoutDashboard,
    LogOut,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import { Icon } from '@/components/opsone/icon';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { Logo } from '@/components/opsone/logo';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
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
 * True while the page shows a copy the service worker served from its cache because the network did not answer in
 * time (public/field-sw.js posts `stale` / `fresh`; a full page load asks with `status`).
 */
function useServedFromCache(): boolean {
    const [stale, setStale] = useState(false);
    useEffect(() => {
        const worker = navigator.serviceWorker;
        if (!worker) {
            return;
        }
        const onMessage = (event: MessageEvent) => {
            if (event.data?.type === 'stale') {
                setStale(true);
            } else if (event.data?.type === 'fresh') {
                setStale(false);
            }
        };
        worker.addEventListener('message', onMessage);
        worker.controller?.postMessage({ type: 'status' });

        return () => worker.removeEventListener('message', onMessage);
    }, []);

    return stale;
}

/**
 * The installation field app frame (ADR-0036): a single-column phone layout with OpsOne's identity only — never
 * the tenant's brand. Registers the service worker (scope /field) and sends queued photos when the connection
 * returns or the app comes back to the foreground. Offline, a banner says what still works.
 */
export default function FieldLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const online = useOnline();
    const stale = useServedFromCache();
    const { auth } = usePage().props;
    const { version } = usePage();
    useFlashToast();

    useEffect(() => {
        if ('serviceWorker' in navigator) {
            // The asset version names the worker's caches: a new build replaces the worker and drops the old copies.
            navigator.serviceWorker
                .register(
                    `/field-sw.js?v=${encodeURIComponent(version ?? '')}`,
                    { scope: '/field' },
                )
                .catch(() => undefined);
        }
    }, [version]);

    useEffect(() => {
        if (online) {
            void flush().catch(() => undefined);
        }
    }, [online]);

    useEffect(() => {
        const onVisible = () => {
            if (document.visibilityState === 'visible' && navigator.onLine) {
                void flush().catch(() => undefined);
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () =>
            document.removeEventListener('visibilitychange', onVisible);
    }, []);

    const signOut = () => {
        navigator.serviceWorker?.controller?.postMessage({ type: 'forget' });
    };

    return (
        <div className="mx-auto flex min-h-dvh w-full max-w-xl flex-col bg-background">
            <header className="sticky top-0 z-20 border-b border-stroke-weak bg-surface pt-[env(safe-area-inset-top)]">
                <div className="flex items-center gap-3 py-1 ps-[max(1rem,env(safe-area-inset-left))] pe-[max(0.5rem,env(safe-area-inset-right))]">
                    <Link
                        href={FieldController.index.url()}
                        className="flex min-h-11 items-center gap-2 no-underline"
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
                                data-test="offline"
                            >
                                <Icon icon={CloudOff} size="sm" />
                                {t('field.offline')}
                            </span>
                        )}
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            aria-label={t('field.install.nav')}
                        >
                            <Link href={FieldController.install.url()}>
                                <Icon icon={CircleHelp} />
                            </Link>
                        </Button>
                    </div>
                </div>
            </header>
            <main className="flex-1 px-4 pt-4 pb-10">
                {!online ? (
                    <Alert
                        variant="warning"
                        className="mb-4"
                        data-test="offline-banner"
                    >
                        <Icon icon={CloudOff} />
                        <AlertBody>
                            <AlertTitle>
                                {t('field.net.offline_title')}
                            </AlertTitle>
                            <p className="m-0 text-caption">
                                {t('field.net.offline_body')}
                            </p>
                        </AlertBody>
                    </Alert>
                ) : (
                    stale && (
                        <Alert
                            variant="info"
                            className="mb-4 items-center"
                            data-test="stale-banner"
                        >
                            <Icon icon={History} />
                            <AlertBody className="text-caption">
                                {t('field.net.stale')}
                            </AlertBody>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.reload()}
                            >
                                {t('field.net.reload')}
                            </Button>
                        </Alert>
                    )
                )}
                {children}
            </main>
            <footer className="flex flex-wrap items-center justify-between gap-x-2 border-t border-stroke-weak px-4 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] text-caption text-weak">
                <span className="min-w-0 truncate">{auth.user.name}</span>
                <div className="flex items-center">
                    <LanguageSwitch />
                    <Button asChild variant="ghost">
                        <Link href={dashboard()}>
                            <Icon icon={LayoutDashboard} size="sm" />
                            {t('field.full_app')}
                        </Link>
                    </Button>
                    <Button asChild variant="ghost">
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

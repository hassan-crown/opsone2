import { Head, Link, usePage } from '@inertiajs/react';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { Logo } from '@/components/opsone/logo';
import { ThemeSwitch } from '@/components/opsone/theme-switch';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const t = useT();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('welcome.title')} />
            <div
                data-surface="site"
                className="flex min-h-dvh flex-col bg-background"
            >
                <header className="flex items-center justify-between gap-2 border-b border-stroke-weak bg-surface p-2 md:px-6">
                    <Logo height={32} />
                    <div className="flex items-center gap-1">
                        <LanguageSwitch />
                        <ThemeSwitch />
                    </div>
                </header>
                <main
                    id="o1-main"
                    className="mx-auto flex w-full max-w-reading flex-1 flex-col items-center justify-center gap-6 p-4 text-center md:p-10"
                >
                    <h1 className="text-display text-strong max-md:text-title">
                        {t('welcome.title')}
                    </h1>
                    <p className="text-body text-weak">
                        {t('welcome.tagline')}
                    </p>
                    <div className="flex flex-wrap justify-center gap-3">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>
                                    {t('nav.dashboard')}
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild>
                                    <Link href={login()}>
                                        {t('auth.login')}
                                    </Link>
                                </Button>
                                <Button asChild variant="secondary">
                                    <Link href={register()}>
                                        {t('auth.sign_up')}
                                    </Link>
                                </Button>
                            </>
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}

import { Link } from '@inertiajs/react';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { Logo } from '@/components/opsone/logo';
import { ThemeSwitch } from '@/components/opsone/theme-switch';
import { useT } from '@/lib/i18n';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const t = useT();

    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <a
                href="#o1-main"
                className="absolute inset-s-4 -top-24 z-50 rounded-md bg-brand px-4 py-2 font-medium text-on-brand focus:top-2"
            >
                {t('ui.skip')}
            </a>
            <header className="flex items-center justify-end gap-1 p-2 md:px-6">
                <LanguageSwitch />
                <ThemeSwitch />
            </header>
            <main
                id="o1-main"
                tabIndex={-1}
                className="flex flex-1 flex-col items-center justify-center gap-6 p-4 outline-none md:p-10"
            >
                <div className="w-full max-w-sm">
                    <div className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-6">
                            <Link
                                href={home()}
                                className="rounded-md"
                                aria-label="OpsOne"
                            >
                                <Logo height={32} />
                            </Link>

                            <div className="space-y-1 text-center">
                                <h1 className="text-title text-strong">
                                    {title && t(title)}
                                </h1>
                                <p className="text-body text-weak">
                                    {description && t(description)}
                                </p>
                            </div>
                        </div>
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}

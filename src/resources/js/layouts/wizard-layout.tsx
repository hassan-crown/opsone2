import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import SetupController from '@/actions/App/Http/Controllers/Org/SetupController';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { Logo } from '@/components/opsone/logo';
import { ThemeSwitch } from '@/components/opsone/theme-switch';
import { Steps } from '@/components/org/steps';
import type { StepItem } from '@/components/org/steps';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { dashboard } from '@/routes';

type WizardProps = {
    facility: { name: string };
    steps: Record<string, 'done' | 'skipped' | null>;
    current: string;
};

const STEP_KEYS = ['org', 'structure', 'flow', 'team', 'review'] as const;

/** Standalone wizard shell: no app navigation, vertical steps beside the step content (stacked on phones). */
export default function WizardLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const { facility, steps, current } = usePage<{
        [key: string]: unknown;
    }>().props as unknown as WizardProps;

    const items: StepItem[] = STEP_KEYS.map((key) => ({
        key,
        label: t(`org.setup.step_${key}` as TranslationKey),
        state: steps[key],
        stateLabel:
            steps[key] === 'done'
                ? t('org.setup.state_done')
                : steps[key] === 'skipped'
                  ? t('org.setup.state_skipped')
                  : t('org.setup.state_pending'),
    }));

    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <a
                href="#o1-main"
                className="absolute inset-s-4 -top-24 z-50 rounded-md bg-brand px-4 py-2 font-medium text-on-brand focus:top-2"
            >
                {t('ui.skip')}
            </a>
            <header className="flex flex-wrap items-center gap-3 border-b border-stroke-weak bg-surface px-4 py-3 md:px-6">
                <Logo height={28} />
                <span className="min-w-0 flex-1 truncate text-control font-semibold text-strong">
                    {t('org.setup.title', { facility: facility.name })}
                </span>
                <Button asChild variant="ghost" size="sm">
                    <Link href={dashboard()}>{t('org.setup.save_later')}</Link>
                </Button>
                <LanguageSwitch />
                <ThemeSwitch />
            </header>
            <div className="mx-auto grid w-full max-w-[72rem] flex-1 items-start gap-6 p-4 md:p-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <Steps
                    label={t('org.setup.steps_label')}
                    steps={items}
                    current={current}
                    renderLink={(step, children) => (
                        <Link
                            href={SetupController.show.url({ step: step.key })}
                            className="block rounded-md no-underline"
                        >
                            {children}
                        </Link>
                    )}
                />
                <main
                    id="o1-main"
                    tabIndex={-1}
                    className="min-w-0 outline-none"
                >
                    {children}
                </main>
            </div>
        </div>
    );
}

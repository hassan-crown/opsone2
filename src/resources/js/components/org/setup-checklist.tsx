import { Link } from '@inertiajs/react';
import SetupController from '@/actions/App/Http/Controllers/Org/SetupController';
import { Progress } from '@/components/org/progress';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type SetupSummary = {
    steps: Record<string, 'done' | 'skipped' | null>;
    done: number;
    total: number;
    next: string;
};

/** HomeSetup: progress of the setup wizard on the home page until it is completed. */
export function SetupChecklist({ setup }: { setup: SetupSummary }) {
    const t = useT();
    const progress = t('org.home.progress', {
        done: setup.done,
        total: setup.total,
    });

    return (
        <Card data-test="setup-checklist" aria-labelledby="setup-title">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="setup-title" className="text-subtitle text-strong">
                    {t('org.home.title')}
                </h2>
                <span className="text-label text-weak">{progress}</span>
            </div>
            <Progress label={progress} value={setup.done} max={setup.total} />
            <p className="text-body text-weak">
                {t('org.home.next', {
                    step: t(`org.setup.step_${setup.next}` as TranslationKey),
                })}{' '}
                {t('org.home.alone')}
            </p>
            <div>
                <Button asChild>
                    <Link href={SetupController.show.url({ step: setup.next })}>
                        {t('org.home.continue')}
                    </Link>
                </Button>
            </div>
        </Card>
    );
}

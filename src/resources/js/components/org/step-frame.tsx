import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Info } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import SetupController from '@/actions/App/Http/Controllers/Org/SetupController';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

const ORDER = ['org', 'structure', 'flow', 'team', 'review'] as const;
export type StepKey = (typeof ORDER)[number];

/**
 * One wizard step: "Step n of 5", heading, body card and the Back / Skip / Next footer.
 * `formId` makes Next submit that form (org step) instead of just marking the step done.
 */
export function StepFrame({
    step,
    description,
    where,
    formId,
    nextLabel,
    hideSkip,
    children,
}: {
    step: StepKey;
    description: string;
    where?: TranslationKey;
    formId?: string;
    nextLabel?: string;
    hideSkip?: boolean;
    children: ReactNode;
}) {
    const t = useT();
    const index = ORDER.indexOf(step);
    const previous = ORDER[index - 1];
    const [busy, setBusy] = useState(false);

    const go = (kind: 'next' | 'skip') => {
        router.post(
            SetupController[kind].url({ step }),
            {},
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <PageHeader
                title={t(`org.setup.step_${step}` as TranslationKey)}
                description={description}
                back={
                    <span className="text-caption text-weak">
                        {t('org.setup.step_of', {
                            n: index + 1,
                            total: ORDER.length,
                        })}
                    </span>
                }
            />
            <Card>{children}</Card>
            {where && (
                <p className="flex items-center gap-2 text-caption text-weak">
                    <Icon icon={Info} size="sm" />
                    {t('org.setup.permanent', { where: t(where) })}
                </p>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                {previous ? (
                    <Button asChild variant="ghost">
                        <Link
                            href={SetupController.show.url({ step: previous })}
                        >
                            <Icon icon={ArrowLeft} />
                            {t('org.setup.back')}
                        </Link>
                    </Button>
                ) : (
                    <span />
                )}
                <div className="flex flex-wrap gap-2 max-sm:w-full max-sm:*:flex-1">
                    {!hideSkip && (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => go('skip')}
                            data-test="wizard-skip"
                        >
                            {t('org.setup.skip')}
                        </Button>
                    )}
                    {formId ? (
                        <Button
                            type="submit"
                            form={formId}
                            data-test="wizard-next"
                        >
                            {nextLabel ?? t('org.setup.next')}
                            <Icon icon={ArrowRight} />
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => go('next')}
                            data-test="wizard-next"
                        >
                            {nextLabel ?? t('org.setup.next')}
                            <Icon icon={ArrowRight} />
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

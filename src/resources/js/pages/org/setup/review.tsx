import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Info } from 'lucide-react';
import { useState } from 'react';
import SetupController from '@/actions/App/Http/Controllers/Org/SetupController';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    steps: Record<string, 'done' | 'skipped' | null>;
    summary: { branches: number; invitations: number; members: number };
};

export default function SetupReview({ steps, summary }: Props) {
    const t = useT();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [busy, setBusy] = useState(false);

    const rows: {
        key: 'org' | 'structure' | 'flow' | 'team';
        detail: string;
    }[] = [
        { key: 'org', detail: t('org.setup.review_org') },
        {
            key: 'structure',
            detail: t('org.setup.review_structure_count', {
                n: summary.branches,
            }),
        },
        { key: 'flow', detail: t('org.setup.review_flow') },
        {
            key: 'team',
            detail:
                summary.invitations > 0
                    ? t('org.setup.review_team_count', {
                          n: summary.invitations,
                      })
                    : t('org.setup.review_team_none'),
        },
    ];

    const complete = () =>
        router.post(
            SetupController.complete.url(),
            {},
            { onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    return (
        <>
            <Head title={t('org.setup.step_review')} />
            <div className="flex flex-col gap-4">
                <PageHeader
                    title={t('org.setup.step_review')}
                    description={t('org.setup.review_intro')}
                    back={
                        <span className="text-caption text-weak">
                            {t('org.setup.step_of', { n: 5, total: 5 })}
                        </span>
                    }
                />
                {errors.setup && (
                    <Alert variant="danger">
                        <AlertBody>{errors.setup}</AlertBody>
                    </Alert>
                )}
                <Card className="gap-0 p-0 md:p-0">
                    <ul className="m-0 list-none divide-y divide-stroke-weak p-0">
                        {rows.map((row) => (
                            <li
                                key={row.key}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 md:px-6"
                            >
                                <span className="flex min-w-0 flex-col">
                                    <span className="font-semibold text-strong">
                                        {t(
                                            `org.setup.step_${row.key}` as TranslationKey,
                                        )}
                                    </span>
                                    <span className="text-caption text-weak">
                                        {row.detail}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            steps[row.key] === 'done'
                                                ? 'success'
                                                : 'warning'
                                        }
                                    >
                                        {steps[row.key] === 'done'
                                            ? t('org.setup.state_done')
                                            : steps[row.key] === 'skipped'
                                              ? t('org.setup.state_skipped')
                                              : t('org.setup.state_pending')}
                                    </Badge>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link
                                            href={SetupController.show.url({
                                                step: row.key,
                                            })}
                                        >
                                            {t('org.setup.review_open')}
                                        </Link>
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card>
                    <h2 className="text-label text-strong">
                        {t('org.setup.on_complete')}
                    </h2>
                    <ul className="m-0 flex list-none flex-col gap-2 p-0">
                        {(
                            [
                                ['org.setup.complete_note_1', Check],
                                ['org.setup.complete_note_2', Info],
                            ] as const
                        ).map(([key, icon]) => (
                            <li
                                key={key}
                                className="flex items-start gap-2 text-body text-strong"
                            >
                                <Icon
                                    icon={icon}
                                    size="sm"
                                    className="mt-1 text-weak"
                                />
                                {t(key)}
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button asChild variant="ghost">
                        <Link href={SetupController.show.url({ step: 'team' })}>
                            <Icon icon={ArrowLeft} />
                            {t('org.setup.back')}
                        </Link>
                    </Button>
                    <Button
                        type="button"
                        loading={busy}
                        onClick={complete}
                        data-test="wizard-complete"
                    >
                        <Icon icon={Check} />
                        {t('org.setup.complete')}
                    </Button>
                </div>
            </div>
        </>
    );
}

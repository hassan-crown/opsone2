import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AiSettingsController from '@/actions/App/Http/Controllers/Ai/AiSettingsController';
import { PageHeader } from '@/components/opsone/page-header';
import { Progress } from '@/components/org/progress';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { edit as autoReplyEdit } from '@/routes/ai-auto-reply';
import { edit } from '@/routes/ai-settings';
import { home } from '@/routes/settings';

type Feature = {
    key: string;
    enabled: boolean;
    capability: 'ai_core' | 'ai_plus';
    licensed: boolean;
};

type Credits = {
    used: number;
    limit: number;
    remaining: number;
    ratio: number;
    month_start: string;
};

type Props = {
    features: Feature[];
    credits: Credits;
    driver: string;
    /** Whether calls can actually leave the server (off until the legal review, Q-94). */
    sendsExternally: boolean;
};

const PRIVACY_POINTS = [
    'ai.privacy.point.minimum',
    'ai.privacy.point.redaction',
    'ai.privacy.point.no_training',
    'ai.privacy.point.no_storage',
    'ai.privacy.point.drafts',
    'ai.privacy.point.transfer',
] as const satisfies readonly TranslationKey[];

export default function SettingsAi({
    features,
    credits,
    driver,
    sendsExternally,
}: Props) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [state, setState] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(features.map((f) => [f.key, f.enabled])),
    );

    const save = () => {
        setBusy(true);
        router.put(
            AiSettingsController.update.url(),
            { features: state },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const exhausted = credits.limit > 0 && credits.remaining === 0;

    return (
        <>
            <Head title={t('ai.settings.title')} />

            <PageHeader
                title={t('ai.settings.title')}
                description={t('ai.settings.description')}
            />

            <div className="max-w-2xl space-y-10">
                <section className="space-y-3" data-test="ai-status">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={sendsExternally ? 'info' : 'neutral'}>
                            {sendsExternally
                                ? t('ai.settings.status.active')
                                : t('ai.settings.status.local')}
                        </Badge>
                        <span className="text-caption text-weak" dir="ltr">
                            {t('ai.settings.driver', { driver })}
                        </span>
                    </div>
                    {!sendsExternally && (
                        <p className="text-body text-weak">
                            {t('ai.settings.status.local_note')}
                        </p>
                    )}
                </section>

                <section
                    className="space-y-3 border-t border-stroke-weak pt-8"
                    data-test="ai-credits"
                >
                    <h2 className="text-subtitle text-strong">
                        {t('ai.credits.title')}
                    </h2>
                    <p className="text-body text-weak">
                        {t('ai.credits.usage', {
                            used: credits.used,
                            limit: credits.limit,
                        })}
                    </p>
                    <Progress
                        label={t('ai.credits.title')}
                        value={credits.used}
                        max={credits.limit}
                    />
                    <p className="text-caption text-weak">
                        {credits.limit === 0
                            ? t('ai.credits.none')
                            : exhausted
                              ? t('ai.credits.exhausted')
                              : t('ai.credits.note')}
                    </p>
                </section>

                <section
                    className="space-y-4 border-t border-stroke-weak pt-8"
                    data-test="ai-features"
                >
                    <div>
                        <h2 className="text-subtitle text-strong">
                            {t('ai.features.title')}
                        </h2>
                        <p className="text-body text-weak">
                            {t('ai.features.description')}
                        </p>
                    </div>
                    <ul className="space-y-3">
                        {features.map((feature) => (
                            <li
                                key={feature.key}
                                className="flex items-start justify-between gap-4"
                            >
                                <label className="flex items-start gap-2 text-body text-strong">
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={state[feature.key] ?? false}
                                        onChange={(e) =>
                                            setState((current) => ({
                                                ...current,
                                                [feature.key]: e.target.checked,
                                            }))
                                        }
                                        data-test={`ai-feature-${feature.key}`}
                                    />
                                    <span>
                                        {t(
                                            `ai.feature.${feature.key}.title` as TranslationKey,
                                        )}
                                        <span className="block text-caption text-weak">
                                            {t(
                                                `ai.feature.${feature.key}.description` as TranslationKey,
                                            )}
                                        </span>
                                        {feature.key === 'auto_reply' && (
                                            <Link
                                                href={autoReplyEdit()}
                                                className="block text-caption text-link underline"
                                                data-test="auto-reply-settings-link"
                                            >
                                                {t(
                                                    'ai.autoreply.settings.card',
                                                )}
                                            </Link>
                                        )}
                                    </span>
                                </label>
                                {!feature.licensed && (
                                    <Badge variant="warning">
                                        {t(
                                            `ai.capability.${feature.capability}.missing` as TranslationKey,
                                        )}
                                    </Badge>
                                )}
                            </li>
                        ))}
                    </ul>
                    <Button
                        type="button"
                        loading={busy}
                        onClick={save}
                        data-test="save-ai"
                    >
                        {t('ai.settings.save')}
                    </Button>
                </section>

                <section
                    className="space-y-3 border-t border-stroke-weak pt-8"
                    data-test="ai-privacy"
                >
                    <h2 className="text-subtitle text-strong">
                        {t('ai.privacy.title')}
                    </h2>
                    <p className="text-body text-weak">
                        {t('ai.privacy.intro')}
                    </p>
                    <ul className="list-disc space-y-1 ps-5 text-body text-weak">
                        {PRIVACY_POINTS.map((key) => (
                            <li key={key}>{t(key)}</li>
                        ))}
                    </ul>
                    <p className="text-caption text-weak">
                        {t('ai.privacy.facility_duty')}
                    </p>
                </section>
            </div>
        </>
    );
}

SettingsAi.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'ai.settings.title', href: edit() },
    ],
};

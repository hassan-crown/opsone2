import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AutoReplySettingsController from '@/actions/App/Http/Controllers/Ai/AutoReplySettingsController';
import { PageHeader } from '@/components/opsone/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { edit } from '@/routes/ai-auto-reply';
import { edit as aiEdit } from '@/routes/ai-settings';
import { home } from '@/routes/settings';

type Mode = 'always' | 'outside_hours';

type Intent = { key: string; enabled: boolean; mode: Mode };

type Template = { name: string; language: string };

type Props = {
    featureEnabled: boolean;
    licensed: boolean;
    inboxEnabled: boolean;
    intents: Intent[];
    handoffNote: boolean;
    template: Template | null;
    templates: (Template & { label: string })[];
    workingHours: { from: string; to: string };
    dailyCap: number;
};

const MODES: Mode[] = ['outside_hours', 'always'];

export default function SettingsAiAutoReply({
    featureEnabled,
    licensed,
    inboxEnabled,
    intents,
    handoffNote,
    template,
    templates,
    workingHours,
    dailyCap,
}: Props) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [state, setState] = useState<Record<string, Intent>>(() =>
        Object.fromEntries(intents.map((i) => [i.key, i])),
    );
    const [note, setNote] = useState(handoffNote);
    const [chosen, setChosen] = useState<string>(
        template === null ? '' : `${template.name}|${template.language}`,
    );

    const update = (key: string, change: Partial<Intent>) =>
        setState((current) => ({
            ...current,
            [key]: { ...current[key], ...change },
        }));

    const save = () => {
        setBusy(true);
        const [name, language] = chosen.split('|');
        router.put(
            AutoReplySettingsController.update.url(),
            {
                intents: Object.fromEntries(
                    Object.values(state).map((i) => [
                        i.key,
                        { enabled: i.enabled, mode: i.mode },
                    ]),
                ),
                handoff_note: note,
                template: chosen === '' ? null : { name, language },
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const ready = featureEnabled && licensed && inboxEnabled;

    return (
        <>
            <Head title={t('ai.autoreply.settings.title')} />

            <PageHeader
                title={t('ai.autoreply.settings.title')}
                description={t('ai.autoreply.settings.description', {
                    cap: dailyCap,
                })}
            />

            <div className="max-w-2xl space-y-10">
                {!ready && (
                    <section
                        className="space-y-2"
                        data-test="auto-reply-not-ready"
                    >
                        {!licensed && (
                            <Badge variant="warning">
                                {t('ai.capability.ai_plus.missing')}
                            </Badge>
                        )}
                        {!featureEnabled && (
                            <p className="text-body text-weak">
                                {t('ai.autoreply.settings.feature_off')}{' '}
                                <Link
                                    href={aiEdit()}
                                    className="text-link underline"
                                >
                                    {t('ai.settings.title')}
                                </Link>
                            </p>
                        )}
                        {!inboxEnabled && (
                            <p className="text-body text-weak">
                                {t('ai.autoreply.settings.inbox_off')}
                            </p>
                        )}
                    </section>
                )}

                <section className="space-y-4" data-test="auto-reply-intents">
                    <div>
                        <h2 className="text-subtitle text-strong">
                            {t('ai.autoreply.settings.intents')}
                        </h2>
                        <p className="text-body text-weak">
                            {t('ai.autoreply.settings.intents_hint', {
                                from: workingHours.from,
                                to: workingHours.to,
                            })}
                        </p>
                    </div>
                    <ul className="space-y-4">
                        {Object.values(state).map((intent) => (
                            <li
                                key={intent.key}
                                className="flex flex-wrap items-start justify-between gap-3"
                            >
                                <label className="flex items-start gap-2 text-body text-strong">
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={intent.enabled}
                                        onChange={(e) =>
                                            update(intent.key, {
                                                enabled: e.target.checked,
                                            })
                                        }
                                        data-test={`auto-reply-${intent.key}`}
                                    />
                                    <span>
                                        {t(
                                            `ai.autoreply.intent.${intent.key}.title` as TranslationKey,
                                        )}
                                        <span className="block text-caption text-weak">
                                            {t(
                                                `ai.autoreply.intent.${intent.key}.description` as TranslationKey,
                                            )}
                                        </span>
                                    </span>
                                </label>
                                <select
                                    aria-label={t(
                                        'ai.autoreply.settings.mode',
                                    )}
                                    className="field-control w-56 text-control"
                                    value={intent.mode}
                                    disabled={!intent.enabled}
                                    onChange={(e) =>
                                        update(intent.key, {
                                            mode: e.target.value as Mode,
                                        })
                                    }
                                    data-test={`auto-reply-mode-${intent.key}`}
                                >
                                    {MODES.map((mode) => (
                                        <option key={mode} value={mode}>
                                            {t(
                                                `ai.autoreply.mode.${mode}` as TranslationKey,
                                            )}
                                        </option>
                                    ))}
                                </select>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="space-y-3 border-t border-stroke-weak pt-8">
                    <label className="flex items-start gap-2 text-body text-strong">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={note}
                            onChange={(e) => setNote(e.target.checked)}
                            data-test="auto-reply-handoff-note"
                        />
                        <span>
                            {t('ai.autoreply.settings.handoff_note')}
                            <span className="block text-caption text-weak">
                                {t('ai.autoreply.settings.handoff_note_hint')}
                            </span>
                        </span>
                    </label>
                </section>

                <section className="space-y-3 border-t border-stroke-weak pt-8">
                    <label
                        htmlFor="auto-reply-template"
                        className="text-subtitle text-strong"
                    >
                        {t('ai.autoreply.settings.template')}
                    </label>
                    <p className="text-body text-weak">
                        {t('ai.autoreply.settings.template_hint')}
                    </p>
                    <select
                        id="auto-reply-template"
                        className="field-control w-full max-w-md text-control"
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                        data-test="auto-reply-template"
                    >
                        <option value="">
                            {t('ai.autoreply.settings.template_none')}
                        </option>
                        {templates.map((tpl) => (
                            <option
                                key={`${tpl.name}|${tpl.language}`}
                                value={`${tpl.name}|${tpl.language}`}
                            >
                                {tpl.label} ({tpl.language})
                            </option>
                        ))}
                    </select>
                </section>

                <Button
                    type="button"
                    loading={busy}
                    onClick={save}
                    data-test="save-auto-reply"
                >
                    {t('ai.settings.save')}
                </Button>
            </div>
        </>
    );
}

SettingsAiAutoReply.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'ai.settings.title', href: aiEdit() },
        { title: 'ai.autoreply.settings.title', href: edit() },
    ],
};

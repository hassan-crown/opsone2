import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ConfigReleaseController from '@/actions/App/Http/Controllers/Rules/ConfigReleaseController';
import Heading from '@/components/heading';
import { Amount } from '@/components/opsone/amount';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import {
    ChangeSummary,
    ReleaseChanges,
} from '@/components/rules/release-changes';
import type { ReleaseChange } from '@/components/rules/release-changes';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { releases as releasesRoute } from '@/routes/rules';
import { home } from '@/routes/settings';

export type Release = {
    id: number;
    number: number;
    published_at: string;
    published_by: string | null;
    notes: string | null;
    restored_from: number | null;
    is_current: boolean;
};

type Props = {
    current: Release | null;
    draft: {
        hash: string;
        base: number;
        changes: ReleaseChange[];
        issues: Record<string, string>;
        pending: boolean;
    };
    releases: (Release & { summary: Record<string, number> })[];
    pinnedOrders: number;
    canEdit: boolean;
};

type PlanPreview = {
    key: string;
    name: string;
    is_default: boolean;
    builtin: boolean;
    dues: {
        key: string;
        name: string;
        share: string | null;
        basis: string;
        event: string;
        stage_key: string | null;
        offset_days: number;
        grace_days: number;
        amount_minor: number;
    }[];
    total_minor: number;
};

type Rendered = { subject: string | null; body: string } | null;

type Preview = {
    value_minor: number;
    plans: { draft: PlanPreview[]; published: PlanPreview[] };
    clauses: {
        draft: Record<string, string[]>;
        published: Record<string, string[]>;
    };
    messages: {
        event: string;
        channel: string;
        draft: Rendered;
        published: Rendered;
    }[];
};

/**
 * Settings › releases (ADR-0040): the business rules are edited as a draft; this page shows what the draft changes
 * against the release in force, tries it on sample data, publishes it as a numbered release with notes, and keeps the
 * history (each release's change log, rollback by publishing a copy, never deleting).
 */
export default function Releases({
    current,
    draft,
    releases,
    pinnedOrders,
    canEdit,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const write = useRulesWrite();
    const [notes, setNotes] = useState('');
    const issues = Object.values(draft.issues);

    const publish = () =>
        write.send('post', ConfigReleaseController.publish().url, {
            base: draft.base,
            draft_hash: draft.hash,
            notes,
        });
    const discard = () => {
        if (window.confirm(t('rules.releases.discard_confirm'))) {
            write.send('post', ConfigReleaseController.discard().url, {
                base: draft.base,
            });
        }
    };
    const firstError =
        write.errors.lock_version ??
        Object.values(write.errors).find((e) => e !== undefined);

    return (
        <>
            <Head title={t('rules.releases.title')} />
            <PageHeader
                title={t('rules.releases.title')}
                description={t('rules.releases.description')}
            />
            <RulesNav current="releases" canEdit={canEdit} />

            <section
                className="mb-8 grid max-w-4xl gap-3"
                data-test="release-current"
            >
                {current === null ? (
                    <Alert variant="warning">
                        <AlertBody>
                            <AlertDescription>
                                {t('rules.releases.none')}
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                ) : (
                    <p className="text-strong">
                        {t('rules.releases.in_force', {
                            number: current.number,
                            date: formatDateTime(current.published_at, locale),
                        })}
                        {pinnedOrders > 0 &&
                            ` ${t('rules.releases.pinned_orders', { count: pinnedOrders })}`}
                    </p>
                )}
            </section>

            <section
                className="mb-8 grid max-w-4xl gap-4"
                data-test="release-draft"
            >
                <Heading
                    variant="small"
                    title={t('rules.releases.draft_title')}
                    description={t('rules.releases.draft_hint')}
                />
                <ReleaseChanges
                    changes={draft.changes}
                    empty={t('rules.releases.no_changes')}
                />
                {issues.length > 0 && (
                    <Alert variant="danger" data-test="release-issues">
                        <AlertBody>
                            <AlertDescription>
                                <p className="mb-1">
                                    {t('rules.releases.issues')}
                                </p>
                                <ul className="list-disc ps-5">
                                    {issues.map((issue) => (
                                        <li key={issue}>{issue}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                )}

                <TryIt />

                {canEdit && draft.pending && (
                    <fieldset
                        className="m-0 grid min-w-0 gap-3 rounded-lg border border-stroke-weak p-4"
                        data-test="release-publish"
                    >
                        <legend className="px-1 text-label">
                            {t('rules.releases.publish_title', {
                                number: draft.base + 1,
                            })}
                        </legend>
                        <p className="text-caption text-weak">
                            {t('rules.releases.publish_hint')}
                        </p>
                        <TextareaField
                            label={t('rules.releases.notes')}
                            rows={2}
                            maxLength={1000}
                            value={notes}
                            error={write.errors.notes}
                            onChange={(e) => setNotes(e.target.value)}
                        />
                        {firstError && (
                            <p className="text-danger-text" role="alert">
                                {firstError}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                onClick={publish}
                                loading={write.busy}
                                disabled={issues.length > 0}
                                data-test="publish-release"
                            >
                                {t('rules.releases.publish')}
                            </Button>
                            {current !== null && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={discard}
                                    disabled={write.busy}
                                    data-test="discard-draft"
                                >
                                    {t('rules.releases.discard')}
                                </Button>
                            )}
                        </div>
                    </fieldset>
                )}
            </section>

            <section className="max-w-4xl" data-test="release-history">
                <Heading variant="small" title={t('rules.releases.history')} />
                {releases.length === 0 ? (
                    <p className="text-weak">{t('rules.releases.none')}</p>
                ) : (
                    <ul className="grid gap-2">
                        {releases.map((r) => (
                            <li
                                key={r.id}
                                className="grid gap-1 rounded-lg border border-stroke-weak p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link
                                        href={ConfigReleaseController.show(
                                            r.id,
                                        )}
                                        className="text-label text-link"
                                    >
                                        {t('rules.releases.release', {
                                            number: r.number,
                                        })}
                                    </Link>
                                    {r.is_current && (
                                        <Badge variant="success">
                                            {t('rules.releases.current')}
                                        </Badge>
                                    )}
                                    {r.restored_from !== null && (
                                        <Badge variant="warning">
                                            {t('rules.releases.restored_from', {
                                                number: r.restored_from,
                                            })}
                                        </Badge>
                                    )}
                                    <span className="text-caption text-weak">
                                        {formatDateTime(r.published_at, locale)}
                                        {r.published_by &&
                                            ` · ${r.published_by}`}
                                    </span>
                                </div>
                                {r.notes && <p className="m-0">{r.notes}</p>}
                                <p className="m-0 text-caption text-weak">
                                    <ChangeSummary summary={r.summary} />
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

/** «Try it»: the draft against the release in force on an agreed value, with clause and message texts. */
function TryIt() {
    const t = useT();
    const { locale } = useLocale();
    const [value, setValue] = useState('37500.00');
    const [language, setLanguage] = useState<string>(locale);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);
        const result = await api<Preview>(
            'POST',
            ConfigReleaseController.preview().url,
            { value, locale: language },
        );
        setBusy(false);
        if (result.ok && result.data) {
            setPreview(result.data);
            setError(null);
        } else {
            setError(
                Object.values(result.errors)[0]?.[0] ??
                    result.message ??
                    t('rules.releases.preview_failed'),
            );
        }
    };

    return (
        <div
            className="grid gap-3 rounded-lg border border-stroke-weak p-4"
            data-test="release-try"
        >
            <Heading
                variant="small"
                title={t('rules.releases.try_title')}
                description={t('rules.releases.try_hint')}
            />
            <div className="flex flex-wrap items-end gap-3">
                <TextField
                    label={t('rules.releases.try_value')}
                    dir="ltr"
                    inputMode="decimal"
                    value={value}
                    error={error ?? undefined}
                    onChange={(e) => setValue(e.target.value)}
                />
                <SelectField
                    label={t('rules.releases.try_language')}
                    value={language}
                    onChange={(e) => setLanguage(e.target.value)}
                    options={[
                        { value: 'ar', label: t('rules.messages.locale.ar') },
                        { value: 'en', label: t('rules.messages.locale.en') },
                    ]}
                />
                <Button
                    type="button"
                    variant="secondary"
                    onClick={run}
                    loading={busy}
                    data-test="run-preview"
                >
                    {t('rules.releases.try_run')}
                </Button>
            </div>
            {preview !== null && <PreviewResult preview={preview} />}
        </div>
    );
}

function PreviewResult({ preview }: { preview: Preview }) {
    const t = useT();

    return (
        <div className="grid gap-4" data-test="preview-result">
            <div className="grid gap-3 lg:grid-cols-2">
                {(['draft', 'published'] as const).map((side) => (
                    <div key={side} className="grid content-start gap-2">
                        <h3 className="text-label text-strong">
                            {t(`rules.releases.side.${side}` as TranslationKey)}
                        </h3>
                        {preview.plans[side].map((plan) => (
                            <table
                                key={plan.key}
                                className="w-full text-caption"
                                data-test={`preview-plan-${side}`}
                            >
                                <caption className="mb-1 text-start text-label">
                                    {plan.name}
                                    {plan.is_default &&
                                        ` · ${t('rules.plans.default')}`}
                                    {plan.builtin &&
                                        ` · ${t('rules.releases.builtin')}`}
                                </caption>
                                <tbody>
                                    {plan.dues.map((due) => (
                                        <tr
                                            key={due.key}
                                            className="border-t border-stroke-weak"
                                        >
                                            <td className="py-1">
                                                {due.name}
                                                {due.share !== null &&
                                                    ` (${due.share}${due.basis === 'percent' ? '%' : ''})`}
                                            </td>
                                            <td className="py-1 text-weak">
                                                {t(
                                                    `finance.event.${due.event}` as TranslationKey,
                                                )}
                                                {due.stage_key !== null && (
                                                    <bdi dir="ltr">{` ${due.stage_key}`}</bdi>
                                                )}
                                                {due.offset_days > 0 &&
                                                    ` +${due.offset_days}`}
                                            </td>
                                            <td className="py-1 text-end">
                                                <Amount
                                                    value={
                                                        due.amount_minor / 100
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="border-t border-stroke-weak font-semibold">
                                        <td className="py-1" colSpan={2}>
                                            {t('rules.releases.total')}
                                        </td>
                                        <td className="py-1 text-end">
                                            <Amount
                                                value={plan.total_minor / 100}
                                                strong
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        ))}
                        {(['quote', 'contract'] as const).map((kind) => (
                            <div key={kind}>
                                <p className="text-caption text-weak">
                                    {t(
                                        `rules.clauses.kind.${kind}` as TranslationKey,
                                    )}
                                </p>
                                {preview.clauses[side][kind].length === 0 ? (
                                    <p className="text-caption text-weak">—</p>
                                ) : (
                                    <ol className="list-decimal ps-5 text-caption">
                                        {preview.clauses[side][kind].map(
                                            (text, i) => (
                                                <li key={i}>{text}</li>
                                            ),
                                        )}
                                    </ol>
                                )}
                            </div>
                        ))}
                    </div>
                ))}
            </div>
            {preview.messages.length > 0 && (
                <div className="grid gap-2" data-test="preview-messages">
                    <h3 className="text-label text-strong">
                        {t('rules.releases.messages_changed')}
                    </h3>
                    {preview.messages.map((m) => (
                        <div
                            key={`${m.event}-${m.channel}`}
                            className="grid gap-1 rounded-md border border-stroke-weak p-2 text-caption"
                        >
                            <span className="text-label">
                                {t(
                                    `rules.messages.event.${m.event}` as TranslationKey,
                                )}{' '}
                                ·{' '}
                                {t(
                                    `rules.messages.channel.${m.channel}` as TranslationKey,
                                )}
                            </span>
                            {(['draft', 'published'] as const).map((side) => (
                                <p key={side} className="m-0">
                                    <span className="text-weak">
                                        {t(
                                            `rules.releases.side.${side}` as TranslationKey,
                                        )}
                                        :{' '}
                                    </span>
                                    {m[side] === null
                                        ? t('rules.releases.builtin')
                                        : [m[side]?.subject, m[side]?.body]
                                              .filter(Boolean)
                                              .join(' — ')}
                                </p>
                            ))}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

Releases.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.releases.title', href: releasesRoute() },
    ],
};

import { Head } from '@inertiajs/react';
import { useState } from 'react';
import DocumentClauseController from '@/actions/App/Http/Controllers/Rules/DocumentClauseController';
import {
    CheckboxField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { clauses as clausesRoute } from '@/routes/rules';
import { home } from '@/routes/settings';

type Text = { ar: string | null; en: string | null };

type Clause = {
    id: number;
    key: string;
    title: Text;
    body: Text;
    applies_to: string[];
    included_by_default: boolean;
    position: number;
    version: number;
    archived: boolean;
    lock_version: number;
};

type Props = {
    clauses: Clause[];
    variables: string[];
    samples: Record<'ar' | 'en', Record<string, string>>;
    canEdit: boolean;
};

type Draft = Omit<Clause, 'id' | 'version' | 'archived' | 'lock_version'> & {
    id: number | null;
    lock_version: number | null;
};

const KINDS = ['quote', 'contract'] as const;

function fill(text: string, values: Record<string, string>): string {
    return text.replace(
        /\{([a-z_]+)\}/g,
        (m, name: string) => values[name] ?? m,
    );
}

/**
 * Settings › clauses (ADR-0037): the library copied into quotes and contracts with its version. Saving a new text adds
 * a version; documents already prepared keep theirs.
 */
export default function Clauses({
    clauses,
    variables,
    samples,
    canEdit,
}: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [draft, setDraft] = useState<Draft | null>(null);

    const save = () => {
        if (!draft) {
            return;
        }
        const target =
            draft.id === null
                ? DocumentClauseController.store()
                : DocumentClauseController.update(draft.id);
        write.send(
            target.method as 'post' | 'put',
            target.url,
            { ...draft, id: undefined },
            () => setDraft(null),
        );
    };

    return (
        <>
            <Head title={t('rules.clauses.title')} />
            <PageHeader
                title={t('rules.clauses.title')}
                description={t('rules.clauses.description')}
            />
            <RulesNav current="clauses" canEdit={canEdit} />
            {clauses.length === 0 && (
                <p className="mb-4 text-weak">{t('rules.clauses.empty')}</p>
            )}
            <ul className="mb-6 grid max-w-4xl gap-3" data-test="clauses-list">
                {clauses.map((c) => (
                    <li
                        key={c.id}
                        className="rounded-lg border border-stroke-weak p-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <strong>{c.title.ar}</strong>
                                <Badge>
                                    {t('rules.clauses.version', {
                                        n: c.version,
                                    })}
                                </Badge>
                                {c.applies_to.map((k) => (
                                    <Badge key={k} variant="info">
                                        {t(
                                            `rules.clauses.kind.${k}` as TranslationKey,
                                        )}
                                    </Badge>
                                ))}
                                {c.archived && (
                                    <Badge>{t('rules.archived')}</Badge>
                                )}
                            </div>
                            {canEdit && !c.archived && (
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            setDraft({
                                                ...c,
                                                title: { ...c.title },
                                                body: { ...c.body },
                                            })
                                        }
                                    >
                                        {t('rules.edit')}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            write.send(
                                                'post',
                                                DocumentClauseController.archive(
                                                    c.id,
                                                ).url,
                                                {},
                                            )
                                        }
                                    >
                                        {t('rules.archive')}
                                    </Button>
                                </div>
                            )}
                        </div>
                        <p className="mt-2 whitespace-pre-line text-weak">
                            {c.body.ar}
                        </p>
                    </li>
                ))}
            </ul>

            {canEdit && draft === null && (
                <Button
                    type="button"
                    onClick={() =>
                        setDraft({
                            id: null,
                            lock_version: null,
                            key: '',
                            title: { ar: '', en: '' },
                            body: { ar: '', en: '' },
                            applies_to: ['contract'],
                            included_by_default: true,
                            position: clauses.length,
                        })
                    }
                    data-test="new-clause"
                >
                    {t('rules.clauses.new')}
                </Button>
            )}

            {draft !== null && (
                <section
                    className="max-w-3xl space-y-4 rounded-lg border border-stroke-weak p-4"
                    data-test="clause-editor"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={t('rules.key')}
                            hint={t('rules.key_hint')}
                            dir="ltr"
                            value={draft.key}
                            error={write.errors.key}
                            onChange={(e) =>
                                setDraft({ ...draft, key: e.target.value })
                            }
                        />
                        <TextField
                            label={t('rules.clauses.position')}
                            type="number"
                            dir="ltr"
                            value={String(draft.position)}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    position: Number(e.target.value) || 0,
                                })
                            }
                        />
                        <TextField
                            label={t('rules.clauses.heading')}
                            value={draft.title.ar ?? ''}
                            error={write.errors['title.ar']}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    title: {
                                        ...draft.title,
                                        ar: e.target.value,
                                    },
                                })
                            }
                        />
                        <TextField
                            label={t('rules.clauses.heading_en')}
                            dir="ltr"
                            value={draft.title.en ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    title: {
                                        ...draft.title,
                                        en: e.target.value,
                                    },
                                })
                            }
                        />
                    </div>
                    <TextareaField
                        label={t('rules.clauses.body')}
                        rows={4}
                        value={draft.body.ar ?? ''}
                        error={write.errors['body.ar']}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                body: { ...draft.body, ar: e.target.value },
                            })
                        }
                    />
                    <TextareaField
                        label={t('rules.clauses.body_en')}
                        dir="ltr"
                        rows={4}
                        value={draft.body.en ?? ''}
                        error={write.errors['body.en']}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                body: { ...draft.body, en: e.target.value },
                            })
                        }
                    />
                    <p className="text-caption text-weak" dir="ltr">
                        {t('rules.variables')}:{' '}
                        {variables.map((v) => `{${v}}`).join(' ')}
                    </p>
                    <div className="rounded-md bg-neutral-bg p-3">
                        <p className="text-label">{t('rules.preview')}</p>
                        <p
                            className="whitespace-pre-line"
                            data-test="clause-preview"
                        >
                            {fill(draft.body.ar ?? '', samples.ar)}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <span className="text-label">
                            {t('rules.clauses.applies')}:
                        </span>
                        {KINDS.map((k) => (
                            <CheckboxField
                                key={k}
                                label={t(
                                    `rules.clauses.kind.${k}` as TranslationKey,
                                )}
                                checked={draft.applies_to.includes(k)}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        applies_to: e.target.checked
                                            ? [...draft.applies_to, k]
                                            : draft.applies_to.filter(
                                                  (x) => x !== k,
                                              ),
                                    })
                                }
                            />
                        ))}
                    </div>
                    {write.errors.applies_to && (
                        <p className="text-danger-text">
                            {write.errors.applies_to}
                        </p>
                    )}
                    <CheckboxField
                        label={t('rules.clauses.default')}
                        checked={draft.included_by_default}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                included_by_default: e.target.checked,
                            })
                        }
                    />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            onClick={save}
                            loading={write.busy}
                            data-test="save-clause"
                        >
                            {t('rules.save')}
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setDraft(null)}
                        >
                            {t('rules.cancel')}
                        </Button>
                    </div>
                </section>
            )}
        </>
    );
}

Clauses.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.clauses.title', href: clausesRoute() },
    ],
};

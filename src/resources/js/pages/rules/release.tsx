import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ConfigReleaseController from '@/actions/App/Http/Controllers/Rules/ConfigReleaseController';
import Heading from '@/components/heading';
import { TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { ReleaseChanges } from '@/components/rules/release-changes';
import type { ReleaseChange } from '@/components/rules/release-changes';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { releases } from '@/routes/rules';
import { home } from '@/routes/settings';
import type { Release } from './releases';

type Props = {
    release: Release;
    previous: number | null;
    changes: ReleaseChange[];
    rollback: ReleaseChange[] | null;
    orders: number;
    base: number;
    canEdit: boolean;
};

/**
 * One configuration release (ADR-0040): who published it, when and why, its change log against the release before it,
 * the orders pinned to it, and — for an older release — what a rollback would change before publishing it as a copy.
 */
export default function ReleasePage({
    release,
    previous,
    changes,
    rollback,
    orders,
    base,
    canEdit,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const write = useRulesWrite();
    const [notes, setNotes] = useState('');

    const rollBack = () => {
        if (
            window.confirm(
                t('rules.releases.rollback_confirm', {
                    number: release.number,
                }),
            )
        ) {
            write.send(
                'post',
                ConfigReleaseController.rollback(release.id).url,
                { base, notes },
            );
        }
    };
    const error =
        write.errors.lock_version ??
        write.errors.release ??
        Object.values(write.errors)[0];

    return (
        <>
            <Head
                title={t('rules.releases.release', { number: release.number })}
            />
            <PageHeader
                title={t('rules.releases.release', { number: release.number })}
                description={[
                    formatDateTime(release.published_at, locale),
                    release.published_by,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            />
            <RulesNav current="releases" canEdit={canEdit} />

            <section className="mb-8 grid max-w-4xl gap-3">
                <div className="flex flex-wrap gap-2">
                    {release.is_current && (
                        <Badge variant="success">
                            {t('rules.releases.current')}
                        </Badge>
                    )}
                    {release.restored_from !== null && (
                        <Badge variant="warning">
                            {t('rules.releases.restored_from', {
                                number: release.restored_from,
                            })}
                        </Badge>
                    )}
                    <Badge variant="neutral">
                        {t('rules.releases.orders_pinned', { count: orders })}
                    </Badge>
                </div>
                {release.notes && <p className="m-0">{release.notes}</p>}
            </section>

            <section className="mb-8 max-w-4xl" data-test="release-log">
                <Heading
                    variant="small"
                    title={
                        previous === null
                            ? t('rules.releases.changes_first')
                            : t('rules.releases.changes_since', {
                                  number: previous,
                              })
                    }
                />
                <ReleaseChanges
                    changes={changes}
                    empty={t('rules.releases.no_changes')}
                />
            </section>

            {rollback !== null && (
                <section
                    className="grid max-w-4xl gap-3"
                    data-test="release-rollback"
                >
                    <Heading
                        variant="small"
                        title={t('rules.releases.rollback_title')}
                        description={t('rules.releases.rollback_hint', {
                            number: release.number,
                            next: base + 1,
                        })}
                    />
                    <ReleaseChanges
                        changes={rollback}
                        empty={t('rules.releases.no_changes')}
                    />
                    {canEdit && (
                        <>
                            <TextareaField
                                label={t('rules.releases.notes')}
                                rows={2}
                                maxLength={1000}
                                value={notes}
                                error={write.errors.notes}
                                onChange={(e) => setNotes(e.target.value)}
                            />
                            {error && (
                                <p className="text-danger-text" role="alert">
                                    {error}
                                </p>
                            )}
                            <div>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={rollBack}
                                    loading={write.busy}
                                    disabled={rollback.length === 0}
                                    data-test="rollback-release"
                                >
                                    {t('rules.releases.rollback', {
                                        number: release.number,
                                    })}
                                </Button>
                            </div>
                        </>
                    )}
                </section>
            )}
        </>
    );
}

ReleasePage.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.releases.title', href: releases() },
    ],
};

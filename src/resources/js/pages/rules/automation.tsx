import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AutomationRuleController from '@/actions/App/Http/Controllers/Rules/AutomationRuleController';
import Heading from '@/components/heading';
import { TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import {
    actionLabel,
    paramLabel,
    triggerLabel,
} from '@/components/rules/automation';
import type { Text } from '@/components/rules/automation';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes/settings';

type Rule = {
    id: number;
    key: string;
    name: Text;
    trigger: string;
    params: Record<string, number> | [];
    conditions: string;
    actions: string[];
    enabled: boolean;
    archived: boolean;
    killed: { at: string; reason: string | null } | null;
    released: 'same' | 'changed' | 'draft';
    stats: { matched: number; skipped: number; failed: number };
    lock_version: number;
};

type Props = {
    rules: Rule[];
    templates: { key: string; name: Text; trigger: string }[];
    canEdit: boolean;
};

const RELEASED_VARIANT = {
    same: 'success',
    changed: 'warning',
    draft: 'info',
} as const;

/**
 * Settings › automation (ADR-0042): rules «عند … وإذا … فافعل …». Saving is a draft until published as a
 * configuration release; the stop switch works at once on every release.
 */
export default function Automation({ rules, templates, canEdit }: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [stopping, setStopping] = useState<number | null>(null);
    const [reason, setReason] = useState('');

    return (
        <>
            <Head title={t('automation.title')} />
            <PageHeader
                title={t('automation.title')}
                description={t('automation.description')}
                actions={
                    canEdit ? (
                        <Button asChild data-test="new-rule">
                            <Link href={AutomationRuleController.create()}>
                                {t('automation.new')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />
            <RulesNav current="automation" canEdit={canEdit} />

            {canEdit && (
                <section className="mb-8 max-w-4xl" data-test="templates">
                    <Heading
                        variant="small"
                        title={t('automation.templates.title')}
                        description={t('automation.templates.hint')}
                    />
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {templates.map((tpl) => (
                            <li key={tpl.key}>
                                <Link
                                    href={AutomationRuleController.create({
                                        query: { template: tpl.key },
                                    })}
                                    className="flex h-full flex-col gap-1 rounded-lg border border-stroke-weak p-4 no-underline hover:bg-hover"
                                    data-test={`template-${tpl.key}`}
                                >
                                    <strong className="text-strong">
                                        {tpl.name.ar}
                                    </strong>
                                    <span className="text-caption text-weak">
                                        {t('automation.when')}{' '}
                                        {triggerLabel(t, tpl.trigger)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {rules.length === 0 ? (
                <p className="text-weak">{t('automation.empty')}</p>
            ) : (
                <ul className="grid max-w-4xl gap-3" data-test="rules-list">
                    {rules.map((rule) => (
                        <li
                            key={rule.id}
                            className="rounded-lg border border-stroke-weak p-4"
                            data-test={`rule-${rule.key}`}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0 space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <strong>{rule.name.ar}</strong>
                                        {rule.archived ? (
                                            <Badge>{t('rules.archived')}</Badge>
                                        ) : (
                                            <Badge
                                                variant={
                                                    RELEASED_VARIANT[
                                                        rule.released
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `automation.released.${rule.released}` as TranslationKey,
                                                )}
                                            </Badge>
                                        )}
                                        {!rule.enabled && (
                                            <Badge>
                                                {t('automation.disabled')}
                                            </Badge>
                                        )}
                                        {rule.killed && (
                                            <Badge
                                                variant="danger"
                                                data-test="killed-badge"
                                            >
                                                {t('automation.stopped')}
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-body">
                                        <span className="font-medium">
                                            {t('automation.when')}
                                        </span>{' '}
                                        {triggerLabel(t, rule.trigger)}
                                        {paramLabel(t, rule.params) &&
                                            ` (${paramLabel(t, rule.params)})`}{' '}
                                        <span className="font-medium">
                                            {t('automation.if')}
                                        </span>{' '}
                                        {rule.conditions}{' '}
                                        <span className="font-medium">
                                            {t('automation.then')}
                                        </span>{' '}
                                        {rule.actions
                                            .map((a) => actionLabel(t, a))
                                            .join('، ')}
                                    </p>
                                    {rule.killed?.reason && (
                                        <p className="text-caption text-danger-text">
                                            {t('automation.stopped_reason', {
                                                reason: rule.killed.reason,
                                            })}
                                        </p>
                                    )}
                                    <p
                                        className="text-caption text-weak"
                                        data-test="rule-stats"
                                    >
                                        {t('automation.stats', {
                                            matched: rule.stats.matched,
                                            skipped: rule.stats.skipped,
                                            failed: rule.stats.failed,
                                        })}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <Link
                                            href={AutomationRuleController.edit(
                                                rule.id,
                                            )}
                                        >
                                            {canEdit && !rule.archived
                                                ? t('rules.edit')
                                                : t('automation.view')}
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost">
                                        <Link
                                            href={AutomationRuleController.log(
                                                rule.key,
                                            )}
                                        >
                                            {t('automation.log.open')}
                                        </Link>
                                    </Button>
                                    {canEdit &&
                                        !rule.archived &&
                                        (rule.killed ? (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    write.send(
                                                        'post',
                                                        AutomationRuleController.revive(
                                                            rule.id,
                                                        ).url,
                                                        {},
                                                    )
                                                }
                                            >
                                                {t('automation.revive')}
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                onClick={() => {
                                                    setStopping(rule.id);
                                                    setReason('');
                                                }}
                                                data-test="stop-rule"
                                            >
                                                {t('automation.stop')}
                                            </Button>
                                        ))}
                                    {canEdit && !rule.archived && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                write.send(
                                                    'post',
                                                    AutomationRuleController.archive(
                                                        rule.id,
                                                    ).url,
                                                    {},
                                                )
                                            }
                                        >
                                            {t('rules.archive')}
                                        </Button>
                                    )}
                                </div>
                            </div>
                            {stopping === rule.id && (
                                <div className="mt-4 flex flex-wrap items-end gap-2 rounded-md bg-danger-bg p-3">
                                    <TextField
                                        label={t('automation.stop_reason')}
                                        hint={t('automation.stop_hint')}
                                        value={reason}
                                        error={write.errors.reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        className="min-w-64 flex-1"
                                    />
                                    <Button
                                        variant="destructive"
                                        loading={write.busy}
                                        onClick={() =>
                                            write.send(
                                                'post',
                                                AutomationRuleController.kill(
                                                    rule.id,
                                                ).url,
                                                { reason },
                                                () => setStopping(null),
                                            )
                                        }
                                        data-test="confirm-stop"
                                    >
                                        {t('automation.stop_confirm')}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        onClick={() => setStopping(null)}
                                    >
                                        {t('rules.cancel')}
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

Automation.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'automation.title', href: AutomationRuleController.index() },
    ],
};

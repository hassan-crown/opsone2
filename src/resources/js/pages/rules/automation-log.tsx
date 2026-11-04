import { Head, Link } from '@inertiajs/react';
import AutomationRuleController from '@/actions/App/Http/Controllers/Rules/AutomationRuleController';
import { PageHeader } from '@/components/opsone/page-header';
import {
    actionLabel,
    fieldLabel,
    OUTCOME_VARIANT,
    reasonLabel,
    triggerLabel,
} from '@/components/rules/automation';
import type { Text } from '@/components/rules/automation';
import { RulesNav } from '@/components/rules/rules-nav';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes/settings';

type Run = {
    id: number;
    at: string;
    outcome: string;
    reason: string | null;
    subject: string;
    release: number | null;
    event: { trigger: string; source: string; occurred_at: string } | null;
    conditions: { field: string; passed: boolean }[];
    actions: { type: string; status: string; reason: string | null }[];
};

type Props = {
    rule: { id: number; key: string; name: Text; killed: boolean };
    runs: Run[];
    canEdit: boolean;
};

const STATUS_VARIANT: Record<string, 'success' | 'neutral' | 'danger'> = {
    done: 'success',
    skipped: 'neutral',
    failed: 'danger',
};

/** Settings › automation › log (ADR-0042): the last runs of one rule — matched, skipped or failed and why. */
export default function AutomationLog({ rule, runs, canEdit }: Props) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <>
            <Head title={t('automation.log.title')} />
            <PageHeader
                title={t('automation.log.title')}
                description={rule.name.ar ?? rule.key}
                back={
                    <Link
                        href={AutomationRuleController.index()}
                        className="text-link"
                    >
                        {t('automation.back')}
                    </Link>
                }
            />
            <RulesNav current="automation" canEdit={canEdit} />
            {runs.length === 0 ? (
                <p className="text-weak">{t('automation.log.empty')}</p>
            ) : (
                <div className="max-w-5xl overflow-x-auto">
                    <table className="w-full text-start" data-test="runs">
                        <thead>
                            <tr className="border-b border-stroke-weak text-label">
                                <th className="p-2 text-start">
                                    {t('automation.log.at')}
                                </th>
                                <th className="p-2 text-start">
                                    {t('automation.log.event')}
                                </th>
                                <th className="p-2 text-start">
                                    {t('automation.log.outcome')}
                                </th>
                                <th className="p-2 text-start">
                                    {t('automation.log.detail')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => (
                                <tr
                                    key={run.id}
                                    className="border-b border-stroke-weak align-top"
                                >
                                    <td className="p-2 whitespace-nowrap">
                                        {formatDateTime(run.at, locale)}
                                    </td>
                                    <td className="p-2">
                                        {run.event
                                            ? triggerLabel(t, run.event.trigger)
                                            : '—'}
                                        <div className="text-caption text-weak">
                                            {run.subject}
                                            {run.release !== null &&
                                                ` · ${t('automation.log.release', { id: run.release })}`}
                                        </div>
                                    </td>
                                    <td className="p-2">
                                        <Badge
                                            variant={
                                                OUTCOME_VARIANT[run.outcome] ??
                                                'neutral'
                                            }
                                        >
                                            {t(
                                                `automation.outcome.${run.outcome}` as TranslationKey,
                                            )}
                                        </Badge>
                                        {run.reason && (
                                            <div className="text-caption text-weak">
                                                {reasonLabel(t, run.reason)}
                                            </div>
                                        )}
                                    </td>
                                    <td className="p-2">
                                        <ul className="flex flex-wrap gap-1">
                                            {run.conditions.map((c, i) => (
                                                <li key={`c${i}`}>
                                                    <Badge
                                                        variant={
                                                            c.passed
                                                                ? 'success'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {c.passed ? '✓' : '✗'}{' '}
                                                        {fieldLabel(t, c.field)}
                                                    </Badge>
                                                </li>
                                            ))}
                                            {run.actions.map((a, i) => (
                                                <li key={`a${i}`}>
                                                    <Badge
                                                        variant={
                                                            STATUS_VARIANT[
                                                                a.status
                                                            ] ?? 'neutral'
                                                        }
                                                    >
                                                        {actionLabel(t, a.type)}
                                                        {a.reason &&
                                                            ` · ${reasonLabel(t, a.reason)}`}
                                                    </Badge>
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}

AutomationLog.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'automation.title', href: AutomationRuleController.index() },
    ],
};

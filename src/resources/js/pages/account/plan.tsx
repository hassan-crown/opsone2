import { Head } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate, formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes/settings';
import { show } from '@/routes/plan';
import type { LicenseStatus } from '@/types';

type Limited = { used: number; limit: number | null };

type Props = {
    plan: string | null;
    status: LicenseStatus;
    effective_status: LicenseStatus | null;
    problem: string | null;
    starts_at: string | null;
    ends_at: string | null;
    grace_ends_at: string | null;
    wind_down_ends_at: string | null;
    phase_ends_at: string | null;
    days_remaining: number | null;
    capabilities: {
        key: 'ready_goods' | 'branch_comparisons';
        enabled: boolean;
    }[];
    usage: {
        seats: Limited & { members: number; pending_invitations: number };
        branches: Limited;
        storage: {
            measured: boolean;
            used_mb: number | null;
            limit: number | null;
        };
    };
};

const BADGE: Record<
    LicenseStatus,
    'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
    provisioned: 'info',
    active: 'success',
    grace: 'warning',
    wind_down: 'warning',
    expired: 'danger',
    needs_attention: 'danger',
};

function UsageMeter({
    label,
    used,
    limit,
    hint,
}: {
    label: string;
    used: number;
    limit: number | null;
    hint?: string;
}) {
    const t = useT();
    const percent =
        limit && limit > 0
            ? Math.min(100, Math.round((used / limit) * 100))
            : null;
    const tone =
        percent === null
            ? 'bg-brand'
            : percent >= 100
              ? 'bg-danger-text'
              : percent >= 80
                ? 'bg-warning-text'
                : 'bg-brand';

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label text-strong">{label}</span>
                <span className="text-body text-strong tabular-nums">
                    {limit === null
                        ? `${formatNumber(used)} · ${t('account.plan.limit_unproven')}`
                        : t('account.plan.of', {
                              used: formatNumber(used),
                              limit: formatNumber(limit),
                          })}
                </span>
            </div>
            {percent !== null && (
                <div
                    role="progressbar"
                    aria-label={label}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={percent}
                    className="h-2 overflow-hidden rounded-full bg-fill"
                >
                    <div
                        className={`h-full rounded-full ${tone}`}
                        style={{ width: `${percent}%` }}
                    />
                </div>
            )}
            {percent !== null && percent >= 80 && (
                <p
                    className={`text-caption ${percent >= 100 ? 'text-danger-text' : 'text-warning-text'}`}
                >
                    {percent >= 100
                        ? t('account.plan.usage_full')
                        : t('account.plan.usage_near', { percent })}
                </p>
            )}
            {hint && <p className="text-caption text-weak">{hint}</p>}
        </div>
    );
}

export default function PlanUsage(props: Props) {
    const t = useT();
    const { locale } = useLocale();
    const date = (value: string | null) =>
        value ? formatDate(value, locale) : t('account.plan.not_available');

    const planName =
        props.plan === 'basic'
            ? t('account.plan.name.basic')
            : props.plan === 'growth'
              ? t('account.plan.name.growth')
              : (props.plan ?? t('account.plan.name.none'));

    const statusText = (): string => {
        if (props.plan === null) {
            return t('account.plan.status_text.none');
        }
        const key =
            `account.plan.status_text.${props.effective_status ?? props.status}` as TranslationKey;
        const base = t(key, { date: date(props.phase_ends_at) });

        return props.status === 'needs_attention'
            ? t('account.plan.status_text.needs_attention')
            : base;
    };

    const terms: [TranslationKey, string][] = [
        ['account.plan.starts_at', date(props.starts_at)],
        ['account.plan.ends_at', date(props.ends_at)],
        ['account.plan.grace_ends_at', date(props.grace_ends_at)],
        ['account.plan.wind_down_ends_at', date(props.wind_down_ends_at)],
        [
            'account.plan.days_remaining',
            props.days_remaining === null
                ? t('account.plan.not_available')
                : t('account.plan.days_value', {
                      days: formatNumber(props.days_remaining),
                  }),
        ],
    ];

    const { seats, branches, storage } = props.usage;

    return (
        <>
            <Head title={t('account.plan.title')} />

            <PageHeader
                title={t('account.plan.title')}
                description={t('account.plan.description')}
            />

            <div className="grid max-w-5xl gap-6 lg:grid-cols-2">
                <Card className="lg:col-span-2">
                    <CardContent className="flex flex-wrap items-center justify-between gap-4">
                        <div className="min-w-0 space-y-2">
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="text-title text-strong">
                                    {planName}
                                </h2>
                                <Badge
                                    variant={BADGE[props.status]}
                                    data-test="plan-status"
                                >
                                    {t(`account.plan.status.${props.status}`)}
                                </Badge>
                            </div>
                            <p className="max-w-reading text-body text-weak">
                                {statusText()}
                            </p>
                        </div>
                        <p className="max-w-[40ch] text-body text-weak">
                            {t('account.plan.manage_note')}
                        </p>
                    </CardContent>
                </Card>

                {props.status === 'needs_attention' && props.problem && (
                    <Alert variant="danger" className="lg:col-span-2">
                        <AlertBody>
                            <AlertTitle>
                                {t('license.needs_attention_title')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('license.needs_attention_body')}{' '}
                                <span dir="ltr">
                                    {t('license.reference', {
                                        code: props.problem,
                                    })}
                                </span>
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>{t('account.plan.term')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="divide-y divide-stroke-weak">
                            {terms.map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex flex-wrap justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                >
                                    <dt className="text-body text-weak">
                                        {t(label)}
                                    </dt>
                                    <dd className="text-body text-strong">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('account.plan.capabilities')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y divide-stroke-weak">
                            {props.capabilities.map((capability) => (
                                <li
                                    key={capability.key}
                                    className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                >
                                    <span className="text-body text-strong">
                                        {t(
                                            `account.plan.capability.${capability.key}`,
                                        )}
                                    </span>
                                    <Badge
                                        variant={
                                            capability.enabled
                                                ? 'success'
                                                : 'neutral'
                                        }
                                    >
                                        <Icon
                                            icon={
                                                capability.enabled
                                                    ? Check
                                                    : Minus
                                            }
                                            size="sm"
                                        />
                                        {capability.enabled
                                            ? t('account.plan.included')
                                            : t('account.plan.not_included')}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{t('account.plan.usage')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-6 md:grid-cols-3">
                        <UsageMeter
                            label={t('account.plan.seats')}
                            used={seats.used}
                            limit={seats.limit}
                            hint={t('account.plan.seats_breakdown', {
                                members: seats.members,
                                pending: seats.pending_invitations,
                            })}
                        />
                        <UsageMeter
                            label={t('account.plan.branches')}
                            used={branches.used}
                            limit={branches.limit}
                        />
                        <UsageMeter
                            label={t('files.plan_storage_mb')}
                            used={storage.used_mb ?? 0}
                            limit={storage.limit}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PlanUsage.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'account.plan.title', href: show() },
    ],
};

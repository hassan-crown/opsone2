import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Gauge } from 'lucide-react';
import { SelectField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { KpiTile } from '@/components/executive/kpi-tile';
import { RankBars } from '@/components/executive/rank-bars';
import type { RankRow } from '@/components/executive/rank-bars';
import { TrendChart } from '@/components/executive/trend-chart';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { useCellFormatter } from '@/components/reports/use-formatters';
import { formatHalalas } from '@/components/reports/format';
import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    ExecutiveDashboardData,
    ExecutiveMetric,
    ExecutivePeriod,
} from '@/types/executive';
import type { BranchOption } from '@/types/reports';

type Props = {
    dashboard: ExecutiveDashboardData;
    branches: BranchOption[];
    filters: {
        period: ExecutivePeriod;
        from: string;
        to: string;
        branch_ids: number[];
    };
};

const PERIODS: ExecutivePeriod[] = ['month', 'last30', 'quarter', 'custom'];

function Section({
    id,
    title,
    hint,
    children,
    className,
}: {
    id: string;
    title: string;
    hint?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Card className={className} data-test={`executive-${id}`}>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {hint && <p className="m-0 text-caption text-weak">{hint}</p>}
            </CardHeader>
            {children}
        </Card>
    );
}

/** OwnerDashboard: P9 metrics for the chosen period and branch, each tile and chart opening its filtered list. */
export default function ExecutiveDashboard({
    dashboard,
    branches,
    filters,
}: Props) {
    const t = useT();
    const format = useCellFormatter();
    const money = t('reports.money_unit');
    const [period, setPeriod] = useState<ExecutivePeriod>(filters.period);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const branch =
        filters.branch_ids.length === 1 ? String(filters.branch_ids[0]) : '';

    const go = (next: Record<string, unknown>) =>
        router.get(
            window.location.pathname,
            { period, ...next },
            { preserveState: true, preserveScroll: true },
        );
    const withBranch = (id: string) =>
        id === '' ? {} : { branch_ids: [Number(id)] };
    const pick = (next: ExecutivePeriod) => {
        setPeriod(next);
        if (next !== 'custom') {
            router.get(
                window.location.pathname,
                { period: next, ...withBranch(branch) },
                { preserveState: true, preserveScroll: true },
            );
        }
    };
    const applyCustom = (event: FormEvent) => {
        event.preventDefault();
        go({ period: 'custom', from, to, ...withBranch(branch) });
    };

    const metric = (key: string): ExecutiveMetric | undefined =>
        dashboard.metrics.find((m) => m.key === key);
    const links = dashboard.links;
    const tile = (
        key: string,
        label: TranslationKey,
        href: string,
        hint?: string,
    ) => {
        const m = metric(key);
        if (m === undefined) {
            return null;
        }

        return (
            <KpiTile
                key={key}
                label={t(label)}
                value={
                    m.kind === 'money'
                        ? formatHalalas(m.value)
                        : format(
                              m.value,
                              m.kind === 'percent' ? 'percent' : 'int',
                          )
                }
                unit={m.kind === 'money' ? money : undefined}
                tone={
                    key === 'collection.overdue_minor' && m.value > 0
                        ? 'danger'
                        : undefined
                }
                hint={hint}
                href={href}
            />
        );
    };

    const created = metric('dashboard.interests_created')?.value ?? 0;
    const tiles = [
        tile('sales.agreed_gross_minor', 'executive.kpi.sales', links.sales),
        tile(
            'collection.collected_minor',
            'executive.kpi.collected',
            links.collection,
        ),
        tile('collection.open_minor', 'executive.kpi.open', links.collection),
        tile(
            'collection.overdue_minor',
            'executive.kpi.overdue',
            links.collection,
        ),
        tile(
            'dashboard.avg_order_minor',
            'executive.kpi.avg_order',
            links.sales,
        ),
        tile(
            'dashboard.interest_conversion_percent',
            'executive.kpi.conversion',
            links.interests_converted,
            t('executive.kpi.conversion_hint', {
                count: formatNumber(created),
            }),
        ),
        tile(
            'sales.open_orders',
            'executive.kpi.open_orders',
            links.orders_open,
        ),
        tile(
            'dashboard.orders_late',
            'executive.kpi.orders_late',
            links.workflow,
        ),
        tile(
            'workflow.tasks_overdue',
            'executive.kpi.tasks_overdue',
            links.workflow,
        ),
        tile(
            'dashboard.installations_next_14d',
            'executive.kpi.installations',
            links.installations,
        ),
    ].filter((x) => x !== null);

    const funnelHref: Record<string, string> = {
        interests: links.interests,
        converted: links.interests_converted,
        agreements: links.sales,
        completed: links.sales,
    };
    const funnel: RankRow[] = dashboard.funnel.map((step, i) => ({
        key: step.key,
        label: t(`executive.funnel.${step.key}` as TranslationKey),
        value: step.value,
        text: formatNumber(step.value),
        href: funnelHref[step.key],
        color: (i % 5) + 1,
    }));
    const stages: RankRow[] = dashboard.stages.map((s) => ({
        key: s.key,
        label: s.label,
        value: s.value,
        text: formatNumber(s.value),
        href: links.orders_open,
        color: 3,
    }));
    const branchRows: RankRow[] = dashboard.branches.rows.map((b) => ({
        key: String(b.branch_id),
        label: b.branch,
        value: b.agreed_gross_minor ?? b.orders_created,
        text:
            b.agreed_gross_minor === null
                ? formatNumber(b.orders_created)
                : `${formatHalalas(b.agreed_gross_minor)} ${money}`,
        sub: [
            t('executive.orders_count', {
                count: formatNumber(b.orders_created),
            }),
            b.collected_net_minor === null
                ? null
                : `${t('executive.chart.collected')} ${formatHalalas(b.collected_net_minor)} ${money}`,
        ]
            .filter(Boolean)
            .join(' · '),
        href: b.href,
        color: 1,
    }));
    const sales: RankRow[] = dashboard.salespeople.map((p) => ({
        key: String(p.id),
        label: p.name,
        value: p.sales_minor,
        text: `${formatHalalas(p.sales_minor)} ${money}`,
        sub: t('executive.orders_count', { count: formatNumber(p.orders) }),
        href: p.href,
        color: 1,
    }));
    const executors: RankRow[] = dashboard.executors.map((e) => ({
        key: String(e.id),
        label: e.name,
        value: e.completed,
        text: t('executive.executor.completed', {
            count: formatNumber(e.completed),
        }),
        sub:
            e.overdue > 0
                ? t('executive.executor.overdue', {
                      count: formatNumber(e.overdue),
                  })
                : undefined,
        href: links.workflow,
        color: 2,
    }));
    const empty = <p className="m-0 text-weak">{t('executive.empty')}</p>;

    return (
        <>
            <Head title={t('executive.title')} />
            <PageHeader
                title={t('executive.title')}
                description={t('executive.desc')}
            />

            <Card className="mb-6" data-test="executive-filters">
                <div
                    role="group"
                    aria-label={t('executive.period.label')}
                    className="flex flex-wrap gap-2"
                >
                    {PERIODS.map((p) => (
                        <Button
                            key={p}
                            type="button"
                            size="sm"
                            variant={period === p ? 'default' : 'secondary'}
                            aria-pressed={period === p}
                            onClick={() => pick(p)}
                        >
                            {t(`executive.period.${p}` as TranslationKey)}
                        </Button>
                    ))}
                </div>
                <form
                    onSubmit={applyCustom}
                    className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end"
                >
                    {period === 'custom' && (
                        <>
                            <TextField
                                type="date"
                                label={t('reports.filters.from')}
                                value={from}
                                max={to || undefined}
                                onChange={(e) => setFrom(e.target.value)}
                            />
                            <TextField
                                type="date"
                                label={t('reports.filters.to')}
                                value={to}
                                min={from || undefined}
                                onChange={(e) => setTo(e.target.value)}
                            />
                        </>
                    )}
                    {branches.length > 1 && (
                        <SelectField
                            label={t('executive.filters.branch')}
                            value={branch}
                            options={[
                                {
                                    value: '',
                                    label: t('executive.filters.all_branches'),
                                },
                                ...branches.map((b) => ({
                                    value: String(b.id),
                                    label: b.name,
                                })),
                            ]}
                            onChange={(e) =>
                                go({
                                    ...(period === 'custom'
                                        ? { from, to }
                                        : {}),
                                    ...withBranch(e.target.value),
                                })
                            }
                        />
                    )}
                    {period === 'custom' && (
                        <Button type="submit">
                            {t('executive.filters.apply')}
                        </Button>
                    )}
                </form>
                <p
                    className="m-0 text-caption text-weak"
                    data-test="executive-range"
                >
                    <Gauge className="me-1 inline size-4" aria-hidden />
                    {t('executive.range', {
                        from: dashboard.range.from,
                        to: dashboard.range.to,
                    })}
                </p>
            </Card>

            <section aria-labelledby="executive-kpis" className="mb-6">
                <h2
                    id="executive-kpis"
                    className="mb-3 text-subtitle text-strong"
                >
                    {t('executive.section.kpis')}
                </h2>
                <div
                    className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 xl:grid-cols-5"
                    data-test="executive-kpis"
                >
                    {tiles}
                </div>
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
                <Section
                    id="trend"
                    title={t('executive.chart.trend')}
                    hint={t('executive.chart.trend_hint')}
                    className="lg:col-span-2"
                >
                    <TrendChart months={dashboard.trend} />
                </Section>

                <Section
                    id="funnel"
                    title={t('executive.chart.funnel')}
                    hint={t('executive.chart.funnel_hint')}
                >
                    <RankBars
                        rows={funnel}
                        label={t('executive.chart.funnel')}
                    />
                </Section>

                <Section id="stages" title={t('executive.chart.stages')}>
                    {stages.length > 0 ? (
                        <RankBars
                            rows={stages}
                            label={t('executive.chart.stages')}
                        />
                    ) : (
                        empty
                    )}
                </Section>

                {dashboard.branches.state === 'available' && (
                    <Section
                        id="branches"
                        title={t('executive.chart.branches')}
                    >
                        <RankBars
                            rows={branchRows}
                            label={t('executive.chart.branches')}
                        />
                        <Link
                            href={links.compare}
                            className="text-link hover:underline"
                        >
                            {t('executive.view_details')}
                        </Link>
                    </Section>
                )}
                {dashboard.branches.state === 'locked' && (
                    <Section
                        id="branches"
                        title={t('executive.chart.branches')}
                    >
                        <Alert variant="info">
                            <AlertBody>
                                {t('executive.branches.locked')}
                            </AlertBody>
                        </Alert>
                    </Section>
                )}

                <Section
                    id="salespeople"
                    title={t('executive.chart.salespeople')}
                >
                    {sales.length > 0 ? (
                        <RankBars
                            rows={sales}
                            label={t('executive.chart.salespeople')}
                        />
                    ) : (
                        empty
                    )}
                </Section>

                <Section id="executors" title={t('executive.chart.executors')}>
                    {executors.length > 0 ? (
                        <RankBars
                            rows={executors}
                            label={t('executive.chart.executors')}
                        />
                    ) : (
                        empty
                    )}
                </Section>
            </div>
        </>
    );
}

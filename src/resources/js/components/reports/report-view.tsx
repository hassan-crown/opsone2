import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { toast } from 'sonner';
import ReportExportController from '@/actions/App/Http/Controllers/Reports/ReportExportController';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type {
    BranchOption,
    ReportFilters,
    ReportPayload,
} from '@/types/reports';
import { useReportLabel } from './labels';
import { ReportChartCard } from './report-chart';
import { ReportFiltersBar } from './report-filters';
import { ReportTable } from './report-table';
import { KpiGrid } from './kpi-tile';

export type ReportViewProps = {
    report: ReportPayload;
    filters: ReportFilters;
    branches: BranchOption[];
    can_export: boolean;
    /** The page URL the filter bar applies to. */
    url: string;
};

/** Shared body of the generic report pages (show, compare): filters, KPI tiles, charts and the details table. */
export function ReportView({
    report,
    filters,
    branches,
    can_export,
    url,
}: ReportViewProps) {
    const t = useT();
    const label = useReportLabel();
    const title = label('title', report.key);

    const requestExport = () =>
        router.post(
            ReportExportController.store.url(),
            {
                report: report.key,
                from: report.range.from,
                to: report.range.to,
                branch_ids: report.branch_ids,
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success(t('reports.exports.requested')),
            },
        );

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={label('desc', report.key)}
                back={
                    <Link
                        className="text-link"
                        href={ReportsController.index.url()}
                    >
                        {t('reports.back')}
                    </Link>
                }
                actions={
                    can_export ? (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={requestExport}
                        >
                            <Download aria-hidden="true" />
                            {t('reports.export')}
                        </Button>
                    ) : undefined
                }
            />
            <ReportFiltersBar url={url} filters={filters} branches={branches} />
            {!report.amounts_visible && (
                <Alert className="mb-6" variant="info">
                    <AlertBody>{t('reports.amounts_hidden')}</AlertBody>
                </Alert>
            )}
            {report.metrics.length > 0 && (
                <section className="mb-6" aria-label={t('reports.summary')}>
                    <KpiGrid metrics={report.metrics} />
                </section>
            )}
            {report.charts.length > 0 && (
                <section className="mb-6" aria-label={t('reports.charts')}>
                    <div className="grid gap-6 lg:grid-cols-2">
                        {report.charts.map((chart, i) => (
                            <ReportChartCard
                                key={chart.key}
                                chart={chart}
                                index={i}
                            />
                        ))}
                    </div>
                </section>
            )}
            <Card>
                <CardHeader>
                    <CardTitle>{t('reports.details')}</CardTitle>
                </CardHeader>
                <ReportTable reportKey={report.key} table={report.table} />
            </Card>
        </>
    );
}

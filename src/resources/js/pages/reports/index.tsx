import { Head, Link } from '@inertiajs/react';
import { GitCompare, Lock } from 'lucide-react';
import ReportExportController from '@/actions/App/Http/Controllers/Reports/ReportExportController';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import { PageHeader } from '@/components/opsone/page-header';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { KpiGrid } from '@/components/reports/kpi-tile';
import { useReportLabel } from '@/components/reports/labels';
import { ReportFiltersBar } from '@/components/reports/report-filters';
import { useT } from '@/lib/i18n';
import type {
    BranchOption,
    ReportCard,
    ReportFilters,
    ReportPayload,
} from '@/types/reports';

type Props = {
    reports: ReportCard[];
    compare: 'available' | 'locked' | 'hidden';
    overview: ReportPayload | null;
    filters: ReportFilters;
    branches: BranchOption[];
    can_export: boolean;
};

/** ReportsHome (SHR-12/13): overview tiles for the period, one card per report, branch comparison when licensed. */
export default function ReportsHome({
    reports,
    compare,
    overview,
    filters,
    branches,
    can_export,
}: Props) {
    const t = useT();
    const label = useReportLabel();

    return (
        <>
            <Head title={t('reports.title.home')} />
            <PageHeader
                title={t('reports.title.home')}
                actions={
                    can_export ? (
                        <Button asChild variant="secondary">
                            <Link href={ReportExportController.index.url()}>
                                {t('reports.exports_link')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />
            <ReportFiltersBar
                url={ReportsController.index.url()}
                filters={filters}
                branches={branches}
            />
            {overview && (
                <section className="mb-6" aria-labelledby="reports-overview">
                    <h2
                        id="reports-overview"
                        className="mb-1 text-subtitle text-strong"
                    >
                        {t('reports.overview')}
                    </h2>
                    <p className="mb-3 text-caption text-weak">
                        {t('reports.overview_hint')}
                    </p>
                    {!overview.amounts_visible && (
                        <Alert className="mb-4" variant="info">
                            <AlertBody>{t('reports.amounts_hidden')}</AlertBody>
                        </Alert>
                    )}
                    {overview.metrics.length > 0 ? (
                        <KpiGrid metrics={overview.metrics} />
                    ) : (
                        <p className="text-weak">{t('reports.empty')}</p>
                    )}
                </section>
            )}
            <div
                className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                data-test="report-cards"
            >
                {reports.map((report) => (
                    <Card key={report.key} className="flex flex-col gap-3">
                        <CardHeader>
                            <CardTitle>{label('title', report.key)}</CardTitle>
                        </CardHeader>
                        <p className="m-0 flex-1 text-body text-weak">
                            {label('desc', report.key)}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="secondary" size="sm">
                                <Link href={report.url}>
                                    {t('reports.open')}
                                </Link>
                            </Button>
                            {report.money &&
                                overview?.amounts_visible === false && (
                                    <Badge variant="neutral">
                                        {t('reports.money_only')}
                                    </Badge>
                                )}
                        </div>
                    </Card>
                ))}
                {compare === 'available' && (
                    <Card className="flex flex-col gap-3">
                        <CardHeader>
                            <CardTitle>{t('reports.title.compare')}</CardTitle>
                        </CardHeader>
                        <p className="m-0 flex-1 text-body text-weak">
                            {t('reports.desc.compare')}
                        </p>
                        <div>
                            <Button asChild variant="secondary" size="sm">
                                <Link href={ReportsController.compare.url()}>
                                    <GitCompare aria-hidden="true" />
                                    {t('reports.compare.available')}
                                </Link>
                            </Button>
                        </div>
                    </Card>
                )}
            </div>
            {compare === 'locked' && (
                <Alert
                    className="mt-6"
                    variant="info"
                    data-test="compare-locked"
                >
                    <Lock aria-hidden="true" />
                    <AlertBody>
                        <AlertTitle>
                            {t('reports.compare.locked_title')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('reports.compare.locked_body')}
                        </AlertDescription>
                    </AlertBody>
                </Alert>
            )}
        </>
    );
}

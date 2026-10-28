import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import { ReportView } from '@/components/reports/report-view';
import type { ReportViewProps } from '@/components/reports/report-view';
import { useT } from '@/lib/i18n';

type Props = Omit<ReportViewProps, 'url'>;

/** Branch comparison: each table row is that branch's own report. */
export default function ReportCompare(props: Props) {
    const t = useT();

    return (
        <>
            <ReportView {...props} url={ReportsController.compare.url()} />
            <p className="mt-3 text-caption text-weak">
                {t('reports.compare.note')}
            </p>
        </>
    );
}

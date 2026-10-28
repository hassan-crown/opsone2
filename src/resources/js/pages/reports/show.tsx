import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import { ReportView } from '@/components/reports/report-view';
import type { ReportViewProps } from '@/components/reports/report-view';

type Props = Omit<ReportViewProps, 'url'>;

/** Generic report page (sales, refunds, commissions, workflow, appointments, inventory, procurement, returns, store). */
export default function ReportShow(props: Props) {
    return (
        <ReportView
            {...props}
            url={ReportsController.show.url(props.report.key)}
        />
    );
}

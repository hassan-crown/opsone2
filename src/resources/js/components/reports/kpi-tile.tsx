import { StatCard } from '@/components/opsone/stat-card';
import type { ReportMetric } from '@/types/reports';
import { useReportLabel } from './labels';
import { useCellFormatter } from './use-formatters';

const COLUMN_OF = {
    count: 'int',
    money: 'money',
    quantity: 'qty',
    hours: 'hours',
    percent: 'percent',
} as const;

/** One metric tile; a metric without a value is not rendered by the caller (never a fake 0). */
export function KpiTile({ metric }: { metric: ReportMetric }) {
    const label = useReportLabel();
    const format = useCellFormatter();

    return (
        <StatCard
            label={label('metric', metric.key)}
            value={
                metric.value === null
                    ? '—'
                    : format(metric.value, COLUMN_OF[metric.kind])
            }
        />
    );
}

export function KpiGrid({ metrics }: { metrics: ReportMetric[] }) {
    return (
        <div
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            data-test="kpis"
        >
            {metrics.map((m) => (
                <KpiTile key={m.key} metric={m} />
            ))}
        </div>
    );
}

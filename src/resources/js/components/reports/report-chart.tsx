import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import type { ReportChart as ChartData } from '@/types/reports';
import { BarChart } from './bar-chart';
import { useReportLabel } from './labels';
import { LineChart } from './line-chart';
import { useChartValue } from './use-formatters';

/** One chart card: resolves the title and (for enum points) the labels, then picks the chart type. */
export function ReportChartCard({
    chart,
    index,
}: {
    chart: ChartData;
    index: number;
}) {
    const label = useReportLabel();
    const value = useChartValue(chart.unit);
    const title = label('chart', chart.key);
    const labels = chart.points.map((p) =>
        p.enum ? label('enum', p.label) : p.label,
    );
    const props = {
        title,
        points: chart.points,
        labels,
        formatValue: (v: number) => value(v),
        formatValueWithUnit: (v: number) => value(v, true),
        colorIndex: index,
    };

    return (
        <Card data-test={`chart-${chart.key}`}>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            {chart.type === 'line' ? (
                <LineChart {...props} />
            ) : (
                <BarChart {...props} />
            )}
        </Card>
    );
}

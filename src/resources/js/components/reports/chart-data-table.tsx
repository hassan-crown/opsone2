import { useT } from '@/lib/i18n';

export type ChartRow = { label: string; value: string };

/** Same data as the chart in a native <details> table: the accessible, non-visual alternative. */
export function ChartDataTable({
    title,
    rows,
}: {
    title: string;
    rows: ChartRow[];
}) {
    const t = useT();

    return (
        <details className="mt-3 text-body">
            <summary className="cursor-pointer text-link">
                {t('reports.chart_table')}
            </summary>
            <div className="mt-2 overflow-x-auto">
                <table className="w-full border-collapse text-start text-body">
                    <caption className="sr-only">{title}</caption>
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="border-b border-stroke-weak px-3 py-2 text-start text-label text-weak"
                            >
                                {title}
                            </th>
                            <th
                                scope="col"
                                className="border-b border-stroke-weak px-3 py-2 text-end text-label text-weak"
                            >
                                #
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, i) => (
                            <tr key={`${row.label}-${i}`}>
                                <td className="border-b border-stroke-weak px-3 py-2">
                                    {row.label}
                                </td>
                                <td className="border-b border-stroke-weak px-3 py-2 text-end tabular-nums">
                                    <bdi dir="ltr">{row.value}</bdi>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

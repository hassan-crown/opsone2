import { EmptyState } from '@/components/opsone/empty-state';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { useT } from '@/lib/i18n';
import type { ColumnType, ReportCell, ReportTableData } from '@/types/reports';
import { Inbox } from 'lucide-react';
import { useReportLabel } from './labels';
import { useCellFormatter } from './use-formatters';

const NUMERIC: ColumnType[] = ['int', 'money', 'qty', 'hours', 'percent'];

export function ReportTable({
    reportKey,
    table,
}: {
    reportKey: string;
    table: ReportTableData;
}) {
    const t = useT();
    const label = useReportLabel();
    const format = useCellFormatter();

    if (table.rows.length === 0) {
        return <EmptyState icon={Inbox} title={t('reports.empty')} compact />;
    }

    const cell = (value: ReportCell, type: ColumnType) => {
        if (value === null || value === undefined) {
            return '—';
        }

        if (type === 'enum') {
            return label('enum', String(value));
        }

        if (type === 'text') {
            return String(value);
        }

        const text = format(
            typeof value === 'boolean' ? String(value) : value,
            type,
        );

        return NUMERIC.includes(type) ||
            type === 'date' ||
            type === 'datetime' ? (
            <bdi dir="ltr">{text}</bdi>
        ) : (
            text
        );
    };

    return (
        <>
            <TableWrap>
                <Table
                    caption={label('title', reportKey)}
                    data-test="report-table"
                >
                    <thead>
                        <tr>
                            {table.columns.map((c) => (
                                <Th
                                    key={c.key}
                                    className={
                                        NUMERIC.includes(c.type)
                                            ? 'text-end'
                                            : undefined
                                    }
                                >
                                    {label('col', c.key)}
                                </Th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {table.rows.map((row, i) => (
                            <tr key={i}>
                                {table.columns.map((c) => (
                                    <Td
                                        key={c.key}
                                        className={
                                            NUMERIC.includes(c.type)
                                                ? 'text-end tabular-nums'
                                                : undefined
                                        }
                                    >
                                        {cell(row[c.key] ?? null, c.type)}
                                    </Td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </TableWrap>
            {table.truncated && (
                <p className="mt-3 text-caption text-weak" role="status">
                    {t('reports.truncated', { count: table.rows.length })}
                </p>
            )}
        </>
    );
}

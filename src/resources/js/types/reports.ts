export type ReportKey =
    | 'sales'
    | 'refunds'
    | 'commissions'
    | 'workflow'
    | 'appointments'
    | 'inventory'
    | 'procurement'
    | 'returns'
    | 'store'
    | 'compare'
    | 'collection';

export type MetricKind = 'count' | 'money' | 'quantity' | 'hours' | 'percent';

export type ReportMetric = {
    key: string;
    kind: MetricKind;
    value: number | null;
};

export type ChartPoint = { label: string; value: number; enum?: true };

export type ReportChart = {
    key: string;
    type: 'bar' | 'line';
    unit: 'count' | 'money' | 'quantity';
    points: ChartPoint[];
};

export type ColumnType =
    | 'text'
    | 'int'
    | 'money'
    | 'qty'
    | 'date'
    | 'datetime'
    | 'hours'
    | 'enum'
    | 'percent';

export type ReportColumn = { key: string; type: ColumnType };

export type ReportCell = string | number | boolean | null;

export type ReportTableData = {
    columns: ReportColumn[];
    rows: Record<string, ReportCell>[];
    truncated: boolean;
};

export type ReportPayload = {
    key: string;
    range: { from: string; to: string };
    branch_ids: number[];
    amounts_visible: boolean;
    metrics: ReportMetric[];
    charts: ReportChart[];
    table: ReportTableData;
};

export type ReportFilters = {
    from: string;
    to: string;
    branch_ids: number[];
};

export type BranchOption = { id: number; name: string };

export type ReportCard = { key: string; url: string; money: boolean };

export type ExportStatus = 'queued' | 'ready' | 'failed';

export type ReportExportRow = {
    id: string;
    report: string;
    status: ExportStatus;
    params: {
        from: string;
        to: string;
        branch_ids: number[];
        locale: string;
    };
    row_count: number | null;
    truncated: boolean;
    size_bytes: number | null;
    requested_at: string | null;
    ready_at: string | null;
    expires_at: string | null;
    downloadable: boolean;
    expired: boolean;
    error: string | null;
};

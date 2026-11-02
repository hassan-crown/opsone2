import type { MetricKind } from '@/types/reports';

export type ExecutivePeriod = 'month' | 'last30' | 'quarter' | 'custom';

export type ExecutiveMetric = {
    key: string;
    kind: MetricKind;
    value: number;
};

export type TrendMonth = {
    month: string;
    from: string;
    to: string;
    href: string;
    sales_minor: number;
    collected_minor: number;
};

export type BranchRow = {
    branch_id: number;
    branch: string;
    orders_created: number;
    agreements_approved: number;
    completed: number;
    tasks_overdue: number;
    agreed_gross_minor: number | null;
    collected_net_minor: number | null;
    href: string;
};

export type ExecutiveDashboardData = {
    period: ExecutivePeriod;
    range: { from: string; to: string };
    branch_ids: number[];
    metrics: ExecutiveMetric[];
    funnel: { key: string; value: number }[];
    trend: TrendMonth[];
    stages: { key: string; label: string; value: number }[];
    branches: {
        state: 'available' | 'single' | 'locked' | 'hidden';
        rows: BranchRow[];
    };
    salespeople: {
        id: number;
        name: string;
        orders: number;
        sales_minor: number;
        href: string;
    }[];
    executors: {
        id: number;
        name: string;
        completed: number;
        overdue: number;
    }[];
    /** ADR-0036: installation visits finished in range, by team. */
    installation_teams: {
        id: number | null;
        name: string;
        visits: number;
        first_visit_percent: number;
        avg_minutes: number;
    }[];
    links: Record<
        | 'sales'
        | 'collection'
        | 'workflow'
        | 'orders_open'
        | 'orders'
        | 'interests'
        | 'interests_converted'
        | 'installations'
        | 'compare',
        string
    >;
};

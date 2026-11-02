/** Server payload of `FinancePresenter::forOrder` (docs/p6-finance-api.md). `*_minor` keys are absent without P-37. */

export type DueStatus =
    | 'upcoming'
    | 'due'
    | 'overdue'
    | 'partially_paid'
    | 'paid';

/** Closed trigger catalogue (ADR-0037). */
export type DueEvent =
    | 'agreement'
    | 'contract.approved'
    | 'design.approved'
    | 'stage.started'
    | 'delivery'
    | 'delivery.completed'
    | 'payment_stage'
    | 'date';

export type PlanLine = {
    key: string;
    name: { ar: string; en?: string | null };
    basis: 'amount' | 'percent' | 'rest';
    value: string | null;
    event: DueEvent;
    stage_key: string | null;
    offset_days: number;
    grace_days: number;
};

export type PaymentPlanOption = {
    id: number;
    key: string;
    name: string;
    is_default: boolean;
    lines: PlanLine[];
};

export type FinanceDue = {
    id: number;
    reference: string;
    name: string | null;
    name_i18n: { ar?: string | null; en?: string | null };
    basis: 'amount' | 'percent' | 'rest';
    event: DueEvent;
    due_date: string | null;
    stage_key: string | null;
    offset_days: number;
    grace_days: number;
    due_on: string | null;
    status: DueStatus;
    basis_value?: number | null;
    amount_minor?: number;
    allocated_minor?: number;
    remaining_minor?: number;
};

export type FileLink = { uuid: string; name: string; url: string | null };

export type FinancePayment = {
    id: number;
    receipt_number: string | null;
    method: string;
    paid_on: string;
    reference: string | null;
    origin: 'staff' | 'customer_proof' | 'gateway';
    status: string;
    recorded_by: string | null;
    created_at: string;
    lock_version: number;
    evidence: FileLink[];
    amount_minor?: number;
    share_minor?: number;
    credit_minor?: number;
    allocations?: { due_id: number; amount_minor: number }[];
};

export type FinanceProof = {
    id: number;
    status: 'under_review' | 'needs_correction' | 'confirmed' | 'cancelled';
    replaced: boolean;
    replaces_id: number | null;
    method: string;
    paid_on: string;
    reference: string | null;
    submitted_at: string;
    correction_reason: string | null;
    decided_by: string | null;
    decided_at: string | null;
    receipt_number: string | null;
    matching_receipt: string | null;
    lock_version: number;
    files: FileLink[];
    claimed_minor?: number;
    similar?: boolean;
};

export type FinanceCondition = {
    reference: string;
    name: string | null;
    basis: string;
    threshold_minor?: number | null;
    met: boolean;
    verified: boolean;
};

export type FinanceSummary = {
    agreed_minor: number | null;
    collected_minor: number;
    remaining_minor: number | null;
    excess_minor: number;
    due_now_minor: number;
    credit_minor: number;
    unscheduled_minor: number | null;
    settlement_needed_minor: number | null;
};

export type FinancePayload = {
    amounts_visible: boolean;
    lock_version: number;
    has_agreement: boolean;
    agreement_version: number | null;
    summary: FinanceSummary | null;
    dues: FinanceDue[];
    payments: FinancePayment[];
    proofs: FinanceProof[];
    conditions: FinanceCondition[];
    shortfalls: string[];
    /** The plan copied into the schedule, or the one the agreement will copy (ADR-0037). */
    plan: {
        key: string;
        name: string | null;
        chosen: boolean;
        copied: boolean;
    };
    /** Active plans; empty without P-38. */
    plans: PaymentPlanOption[];
    stages: { key: string; name: string }[];
    /** Owner setting (ADR-0014): false lets a cash collection go without a proof file, with a note. */
    cash_proof_required: boolean;
    can: {
        record: boolean;
        review: boolean;
        schedule: boolean;
        allocate: boolean;
        plan: boolean;
    };
    customer_orders: { id: number; number: string }[];
};

export type FinanceResponse = { finance: FinancePayload };

export type WorkItem = {
    kind: 'overdue' | 'due' | 'proof' | 'settlement' | 'refund' | 'dispute';
    key: string;
    due_id?: number;
    proof_id?: number;
    refund_id?: number;
    dispute_id?: number;
    order: { id: number; number: string; customer: string };
    label: string | null;
    date: string;
    age_days: number;
    amount_minor: number;
};

export type ReminderRecipient = {
    due_id: number;
    order: { id: number; number: string };
    customer: string;
    channels: string[];
    amount_minor: number;
    excluded: 'disputed' | 'nothing_due' | null;
};

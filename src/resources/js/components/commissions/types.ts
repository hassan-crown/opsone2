export type RateInfo = { rate_bp: number; source: string };

export type CommissionSummary = {
    accrued_minor: number;
    paid_minor: number;
    reserved_minor: number;
    held_minor: number;
    available_minor: number;
    sales: number;
};

export type MemberRow = CommissionSummary & {
    id: number;
    name: string;
    rate: RateInfo;
    open_payout: { id: number; status: PayoutStatus } | null;
    can_pay: boolean;
};

export type PayoutStatus =
    | 'draft'
    | 'reserved'
    | 'paid'
    | 'cancelled'
    | 'corrected';

export type Payout = {
    id: number;
    status: PayoutStatus;
    period_from: string;
    period_to: string;
    amount_minor: number | null;
    corrected_minor: number | null;
    method: string | null;
    paid_on: string | null;
    reference: string | null;
    note: string | null;
    reason: string | null;
    lock_version: number;
    files: { uuid: string; name: string; url: string | null }[];
    can: {
        approve: boolean;
        record: boolean;
        cancel: boolean;
        correct: boolean;
    };
};

export type SaleRow = {
    id: number;
    status: 'active' | 'superseded';
    order: { id: number; number: string; customer: string };
    rate_bp: number;
    rate_source: string;
    pinned_at: string;
    eligible_minor: number | null;
    agreed_minor: number | null;
    collected_minor: number | null;
    accrued_minor: number;
    taken_minor: number;
    available_minor: number;
    hold: string | null;
};

export type Entry = {
    id: number;
    kind: 'accrual' | 'transfer_out' | 'transfer_in';
    order?: { id: number; number: string };
    amount_minor: number;
    accrued_minor: number;
    collected_minor?: number | null;
    source: string;
    reason: string | null;
    created_at: string;
};

export type Payable = {
    lines: {
        order_sale_id: number | null;
        order_id: number | null;
        amount_minor: number;
    }[];
    amount_minor: number;
    held: { order_id: number; hold: string; amount_minor: number }[];
};

export type Statement = {
    member: { id: number; name: string; rate: RateInfo };
    summary: CommissionSummary;
    sales: SaleRow[];
    entries: Entry[];
    payouts: Payout[];
    payable: Payable | null;
    can: { pay: boolean };
    today: string;
};

export type Correction = {
    id: number;
    order: { id: number; number: string };
    kind: 'owner' | 'rate';
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    from: string | null;
    to: string | null;
    from_rate_bp: number;
    to_rate_bp: number;
    reason: string;
    decision_reason: string | null;
    requested_by: string | null;
    decided_by: string | null;
    created_at: string;
    lock_version: number;
    can: { decide: boolean; cancel: boolean };
};

export type SalePayload = {
    sale: {
        id: number;
        member: { id: number; name: string };
        rate_bp: number;
        rate_source: string;
        owner_source: string;
        pinned_at: string;
        eligible_minor?: number;
        collected_minor?: number;
        agreed_minor?: number;
        target_minor?: number;
        accrued_minor?: number;
    } | null;
    amounts_visible: boolean;
    history: {
        id: number;
        status: 'active' | 'superseded';
        member: string;
        rate_bp: number;
        pinned_at: string;
        entries: Entry[];
    }[];
    corrections: Correction[];
    members: { id: number; name: string }[];
    can: { request_owner: boolean; request_rate: boolean };
};

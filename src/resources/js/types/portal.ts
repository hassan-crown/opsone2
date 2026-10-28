/** Customer workspace payloads (App\Portal\PortalPresenter). Customer-safe by construction: no staff-only fields. */

export type PortalShared = {
    facility: string | null;
    customer: { name: string } | null;
};

export type PortalOrder = {
    id: number;
    number: string;
    kind: 'custom' | 'ready';
    status: string;
    open: boolean;
    title: string | null;
    created_on: string;
    stage: string | null;
};

export type PortalDue = {
    id: number;
    name: string | null;
    due_on: string | null;
    status: 'upcoming' | 'due' | 'overdue' | 'partially_paid' | 'paid';
    amount_minor: number;
    remaining_minor: number;
};

export type PortalFinance = {
    agreed_minor: number | null;
    collected_minor: number;
    remaining_minor: number | null;
    due_now_minor: number;
    pending_proofs_minor: number;
    dues: PortalDue[];
};

export type PortalFile = { name: string; size_bytes: number; url: string };

export type PortalProof = {
    id: number;
    status: 'under_review' | 'needs_correction' | 'confirmed' | 'cancelled';
    claimed_minor: number;
    method: string;
    paid_on: string;
    reference: string | null;
    correction_reason: string | null;
    replaced: boolean;
    submitted_at: string;
    files: PortalFile[];
};

export type PortalReceipt = {
    id: number;
    number: string;
    amount_minor: number;
    issued_on: string;
    order: string;
    order_id: number;
};

export type PortalAppointment = {
    id: number;
    kind: string;
    status: 'awaiting_confirmation' | 'confirmed' | 'done' | 'cancelled';
    starts_at: string;
    ends_at: string;
    address: string;
    maps_url: string;
    order: string;
    order_id: number;
    proposed: { starts_at: string; ends_at: string } | null;
    customer_request: {
        note: string;
        preferred: string | null;
        requested_at: string;
    } | null;
    can: { confirm: boolean; request_change: boolean };
};

export type PortalDocumentSummary = {
    id: number;
    kind: 'quote' | 'design' | 'contract';
    number: string;
    status: string;
    order: string;
    order_id: number;
    sent_on: string | null;
    decided_at: string | null;
};

export type PortalDocumentLine = {
    description?: string;
    quantity?: string;
    unit?: string | null;
    unit_price_minor?: number | null;
    gross_minor?: number | null;
};

export type PortalDocument = PortalDocumentSummary & {
    content: {
        description?: string;
        lines?: PortalDocumentLine[];
        totals?: {
            discount_minor?: number | null;
            net_minor?: number | null;
            vat_minor?: number | null;
            total_minor?: number | null;
        };
        vat?: { rate_bp?: number; inclusive?: boolean };
        execution_days?: number | null;
        terms?: string | string[] | null;
        agreement_terms?: string | null;
        clauses?: string[];
    };
    content_hash: string;
    valid_until: string | null;
    files: PortalFile[];
    decision: {
        decision: string;
        decided_at: string;
        by_customer: boolean;
        note: string | null;
    } | null;
    replaced_by: { id: number; number: string } | null;
    can: { decide: boolean };
};

export type PortalCancellation = {
    id: number;
    number: string;
    status: 'pending' | 'accepted' | 'rejected' | 'withdrawn';
    reason: string | null;
    requested_on: string;
    decision_reason: string | null;
    decided_at: string | null;
    can_withdraw: boolean;
};

export type PortalOrderDetail = PortalOrder & {
    lines: {
        description: string | null;
        quantity: string;
        unit: string | null;
    }[];
    timeline: {
        name: string | null;
        status: string;
        started_on: string | null;
        completed_on: string | null;
    }[];
    finance: PortalFinance;
    proofs: PortalProof[];
    receipts: PortalReceipt[];
    appointments: PortalAppointment[];
    documents: PortalDocumentSummary[];
    cancellation: PortalCancellation | null;
    can: { upload_proof: boolean; request_cancellation: boolean };
};

export type PortalRequired = {
    type: 'document' | 'appointment' | 'proof' | 'payment';
    id: number;
    order: string;
    order_id: number;
    kind?: string;
    number?: string;
    since?: string | null;
    starts_at?: string;
    reason?: string | null;
    amount_minor?: number;
    pending_minor?: number;
};

export type PortalUpdate = {
    type: string;
    at: string;
    order: string;
    order_id: number;
    ref: string | null;
    kind?: string;
    amount_minor?: number;
    starts_at?: string;
    link: ['document' | 'order', number];
};

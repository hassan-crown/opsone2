/** Payload of `AgreementPresenter::forOrder` (docs/p6-finance-api.md). Every `*_minor` key is absent when amounts are hidden. */

export type Totals = {
    base_minor: number | null;
    discount_minor: number | null;
    net_minor: number | null;
    vat_minor: number | null;
    total_minor: number | null;
    products_minor: number | null;
};

export type PricedLine = {
    order_line_id: number | null;
    description: string;
    quantity: string;
    unit: string | null;
    commissionable: boolean;
    unit_price_minor?: number | null;
    amount_minor?: number | null;
    discount_minor?: number;
    net_minor?: number;
    vat_minor?: number;
    gross_minor?: number;
};

export type Discount = {
    type: 'none' | 'percent' | 'amount';
    value: number;
    amount_minor: number | null;
};

export type PricedContent = {
    lines: PricedLine[];
    discount?: Discount;
    vat: { rate_bp: number; inclusive: boolean };
    totals?: Totals;
    complete: boolean;
    terms: string | null;
    execution_days: number | null;
};

export type FileRef = { uuid: string; name: string; url: string | null };

export type CustomerDecisionInfo = {
    decision: 'approved' | 'changes_requested';
    method: 'customer_account' | 'external';
    channel: string | null;
    decided_at: string;
    recorded_by: string | null;
    customer_mobile: string | null;
    note: string | null;
    evidence: FileRef[];
};

export type DiscountDecisionRef = {
    id: number;
    status: string;
    requester: string;
    decider: string | null;
    decided_at: string | null;
};

export type DiscountState = {
    state:
        | 'none'
        | 'within_limit'
        | 'approved'
        | 'requested'
        | 'needs_decision'
        | 'incomplete';
    limit_bp: number | null;
    share_bp: number;
    over_minor: number;
    decision_id: number | null;
    decision: DiscountDecisionRef | null;
};

export type DocumentKind = 'quote' | 'design' | 'contract';

export type AgreementDocument = {
    id: number;
    kind: DocumentKind;
    number: string;
    status: string;
    replaces: string | null;
    valid_until: string | null;
    sent_at: string | null;
    sent_by: string | null;
    decided_at: string | null;
    cancelled_reason: string | null;
    /** Quote: PricedContent; design: {description, order_line_ids, file}; contract: {source, parties, …}. */
    content: Record<string, unknown>;
    content_hash: string | null;
    lock_version: number;
    files: FileRef[];
    decision: CustomerDecisionInfo | null;
    /** Present only with amounts visible. */
    discount?: DiscountState | null;
    can: {
        edit: boolean;
        send: boolean;
        revise: boolean;
        replace_design: boolean;
        extend: boolean;
        cancel: boolean;
        record_approval: boolean;
        request_discount: boolean;
    };
};

export type ActiveAgreement = {
    id: number;
    version: number;
    approved_at: string;
    source: { type: 'document' | 'change'; number: string | null };
    decision: CustomerDecisionInfo;
    content: PricedContent;
    versions: {
        version: number;
        status: string;
        approved_at: string;
        total_minor?: number;
    }[];
    total_minor?: number;
    net_minor?: number;
    vat_minor?: number;
    discount_minor?: number;
    products_minor?: number;
    difference_minor?: number;
    settlement_needed_minor?: number;
};

export type AgreementChange = {
    id: number;
    number: string;
    status: string;
    description: string;
    base_version: number;
    content: PricedContent;
    affected: { key: string; name: string; held: boolean }[];
    delay_days: number;
    revision_reason: string | null;
    cancelled_reason: string | null;
    presented_at: string | null;
    presentations: number;
    content_hash: string | null;
    lock_version: number;
    discount?: DiscountState | null;
    difference_minor?: number | null;
    base_total_minor?: number;
    can: {
        edit: boolean;
        present: boolean;
        request_revision: boolean;
        apply: boolean;
        withdraw: boolean;
        request_discount: boolean;
    };
};

export type HistoryItem = {
    id: number;
    machine: 'M-08' | 'M-09' | 'M-26';
    transition: string;
    to: string;
    actor: string | null;
    source: string;
    reason: string | null;
    at: string;
};

export type AgreementPayload = {
    amounts_visible: boolean;
    vat: { rate_bp: number; inclusive: boolean };
    contracts_enabled: boolean;
    agreement: ActiveAgreement | null;
    documents: AgreementDocument[];
    change: AgreementChange | null;
    history: HistoryItem[];
    can: {
        create_quote: boolean;
        upload_design: boolean;
        prepare_contract: boolean;
        create_change: boolean;
    };
};

export type FlowActionOption = {
    key: string;
    name: string;
    type: string;
    status: string;
};

/** Lines as the editor holds them: decimal text, converted on the server. */
export type EditableLine = {
    key: string;
    order_line_id: number | null;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
    commissionable: boolean;
};

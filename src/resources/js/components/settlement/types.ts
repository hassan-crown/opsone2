import type { FileLink, FinancePayload } from '@/components/finance/types';

/** Server payload of `SettlementPresenter::forOrder` (docs/p6-finance-api.md «6c»). `*_minor` keys are absent without P-37. */

export type RefundStatus =
    | 'requested'
    | 'in_progress'
    | 'unresolved'
    | 'executed'
    | 'failed'
    | 'cancelled';

export type DisputeStatus = 'open' | 'awaiting_external' | 'resolved';

export type DocumentStatus =
    | 'requested'
    | 'attached'
    | 'accepted'
    | 'needs_correction';

export type SettlementPayment = {
    id: number;
    receipt_number: string | null;
    method: string;
    paid_on: string;
    reference: string | null;
    status: string;
    lock_version: number;
    refundable: boolean;
    correctable: boolean;
    split: boolean;
    amount_minor?: number;
    placed_minor?: number;
    reserved_minor?: number;
    refunded_minor?: number;
    refundable_minor?: number;
};

export type SettlementRefund = {
    id: number;
    number: string | null;
    payment_id: number;
    receipt_number: string | null;
    status: RefundStatus;
    method: string;
    reason: string;
    attempt: number;
    reference: string | null;
    failure_reason: string | null;
    cancel_reason: string | null;
    requested_by: string | null;
    requested_at: string;
    executed_by: string | null;
    executed_at: string | null;
    lock_version: number;
    files: FileLink[];
    can: {
        start: boolean;
        confirm: boolean;
        fail: boolean;
        retry: boolean;
        cancel: boolean;
    };
    amount_minor?: number;
};

export type SettlementCorrection = {
    id: number;
    number: string;
    kind: 'void' | 'amend';
    reason: string;
    receipt_number: string | null;
    replacement_receipt: string | null;
    corrected_by: string | null;
    created_at: string;
    before_minor?: number;
    after_minor?: number;
};

export type SettlementDispute = {
    id: number;
    subject: string;
    reason: string;
    status: DisputeStatus;
    receipt_number: string | null;
    refund_number: string | null;
    due_name: string | null;
    responsible: { id: number; name: string };
    external_party: string | null;
    outcome: string | null;
    resolution: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    opened_at: string;
    age_days: number;
    lock_version: number;
    files: FileLink[];
    can: { await: boolean; resume: boolean; resolve: boolean };
    amount_minor?: number;
    amount_note?: string | null;
};

export type ExternalDocument = {
    id: number;
    order_id: number;
    kind: string;
    event: string;
    payment_id: number | null;
    receipt_number: string | null;
    refund_number: string | null;
    source: string | null;
    number: string | null;
    issued_on: string | null;
    currency: string;
    note: string | null;
    status: DocumentStatus;
    correction_reason: string | null;
    reviewed_by: string | null;
    lock_version: number;
    files: FileLink[];
    can: { attach: boolean; review: boolean };
    amount_minor?: number | null;
    order?: { id: number; number: string; customer: string };
};

export type SettlementPayload = {
    amounts_visible: boolean;
    payments: SettlementPayment[];
    refunds: SettlementRefund[];
    corrections: SettlementCorrection[];
    disputes: SettlementDispute[];
    documents: ExternalDocument[];
    open_disputes: number;
    customer_tax: {
        name: string;
        kind: string;
        vat_number: string | null;
        commercial_registration: string | null;
        missing: string[];
    };
    members: { id: number; name: string }[];
    can: {
        request_refund: boolean;
        execute_refund: boolean;
        correct: boolean;
        dispute: boolean;
        documents: boolean;
    };
};

export type SettlementResponse = {
    finance: FinancePayload;
    settlement: SettlementPayload;
    number?: string | null;
};

export type SettlementDone = (response: SettlementResponse) => void;

export const DOCUMENT_KINDS = [
    'tax_invoice',
    'simplified_invoice',
    'credit_note',
    'debit_note',
] as const;

export const DOCUMENT_EVENTS = [
    'payment',
    'refund',
    'delivery',
    'order',
] as const;

export const DISPUTE_SUBJECTS = [
    'duplicate',
    'amount',
    'settlement',
    'refund',
    'other',
] as const;

export const DISPUTE_OUTCOMES = [
    'upheld',
    'partly_upheld',
    'rejected',
] as const;

import type { Page } from '@/types/catalog';

export type { Page };

export type ReturnStatus =
    | 'requested'
    | 'accepted'
    | 'rejected'
    | 'received'
    | 'inspected'
    | 'cancelled';

export type ReturnOrderRef = {
    id: number;
    number: string;
    status: string;
    customer: string;
    branch_id: number;
};

export type EligibleLine = {
    order_line_id: number;
    position: number;
    name: string;
    unit: string | null;
    unit_price_minor?: number;
    delivered: string;
    held: string;
    eligible: string;
    delivered_on: string | null;
    overdue: boolean;
};

export type EligibleOrder = {
    id: number;
    number: string;
    status: string;
    customer: string;
    lines: number;
};

export type ReturnRow = {
    id: number;
    number: string;
    status: ReturnStatus;
    order: { id: number; number: string };
    customer: string;
    reason: string;
    created_at: string;
    refund_asked: boolean;
    /** Present only with P-37. */
    settlement_minor?: number;
};

export type ReturnLine = {
    order_line_id: number;
    position: number;
    name: string;
    unit: string | null;
    unit_price_minor?: number;
    value_minor?: number;
    requested: string;
    accepted: string;
    received: string;
    outstanding: string;
    good: string;
    damaged: string;
    rejected: string;
    eligible: string | null;
};

export type ReturnPart = {
    id: number;
    order_line_id: number | null;
    location: { id: number; code: string; name: string | null };
    quantity: string;
    received_at: string;
    received_by: string | null;
    inspected: boolean;
    good: string | null;
    damaged: string | null;
    rejected: string | null;
    inspection_note: string | null;
    can_correct: boolean;
};

export type ReturnDetail = Omit<ReturnRow, 'order'> & {
    source: string;
    lock_version: number;
    order: ReturnOrderRef;
    requested_by: string | null;
    decision_reason: string | null;
    decided_by: string | null;
    decided_at: string | null;
    cancel_reason: string | null;
    window_days: number;
    delivered_on: string | null;
    overdue: boolean;
    lines: ReturnLine[];
    parts: ReturnPart[];
    settlement: {
        value_minor: number | null;
        settlement_minor: number | null;
    } | null;
    refund: {
        id: number;
        number: string | null;
        status: string;
        amount_minor?: number;
    } | null;
    payments: {
        id: number;
        receipt_number: string | null;
        refundable_minor: number;
    }[];
    locations: { id: number; code: string; name: string | null }[];
    can: {
        accept: boolean;
        reject: boolean;
        withdraw: boolean;
        receive: boolean;
        inspect: boolean;
        refund: boolean;
    };
};

import type { Page } from '@/types/catalog';

export type { Page };

export type DeliveryStatus = 'draft' | 'confirmed' | 'cancelled';

export type OrderRef = {
    id: number;
    number: string;
    kind: 'custom' | 'ready';
    status: string;
    customer: string;
    branch_id: number;
    lock_version: number;
};

export type OwedLine = {
    order_line_id: number;
    position: number;
    type: string;
    name: string;
    description: string | null;
    unit: string | null;
    unit_price_minor?: number;
    ordered: string;
    delivered: string;
    released: string;
    deliverable: string;
};

export type AwaitingOrder = {
    id: number;
    number: string;
    kind: 'custom' | 'ready';
    status: string;
    customer: string;
    lines: number;
    partial: boolean;
    ready: boolean;
};

export type DeliveryRow = {
    id: number;
    number: string | null;
    status: DeliveryStatus;
    order: { id: number; number: string; kind: 'custom' | 'ready' };
    customer: string;
    delivered_on: string | null;
    receiver_name: string | null;
    created_at: string;
};

export type DeliveryLine = {
    id: number;
    order_line_id: number;
    position: number;
    name: string;
    unit: string | null;
    unit_price_minor?: number;
    value_minor?: number;
    quantity: string;
    corrected: string;
    net: string;
    deliverable: string | null;
};

export type DeliveryCorrection = {
    id: number;
    destination: 'stock' | 'reservation' | null;
    reason: string;
    by: string | null;
    at: string;
    first_delivery_on: string | null;
    lines: { line_id: number; quantity: string }[];
};

export type DeliveryDetail = DeliveryRow & {
    note: string | null;
    lock_version: number;
    cancel_reason: string | null;
    confirmed_by: string | null;
    confirmed_at: string | null;
    order: OrderRef;
    lines: DeliveryLine[];
    proofs: { uuid: string; name: string; url: string | null }[];
    corrections: DeliveryCorrection[];
    can: {
        confirm: boolean;
        cancel: boolean;
        correct: boolean;
        correct_needs_destination: boolean;
    };
};

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
    order_line_id: number;
    position: number;
    name: string;
    unit: string | null;
    unit_price_minor?: number;
    value_minor?: number;
    quantity: string;
    deliverable: string | null;
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
    can: { confirm: boolean; cancel: boolean };
};

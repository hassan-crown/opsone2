import type { Page, Text } from '@/types/catalog';

export type { Page, Text };

export type PurchaseStatus =
    | 'draft'
    | 'ordered'
    | 'partially_received'
    | 'received'
    | 'cancelled_remainder';

export type Option = { id: number; name: string };

export type SupplierRow = {
    id: number;
    name: Text;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    vat_number: string | null;
    supplies: string | null;
    notes: string | null;
    status: 'active' | 'disabled';
    lock_version: number;
};

export type SupplierOption = { id: number; name: Text };

export type PurchaseRow = {
    id: number;
    number: string;
    status: PurchaseStatus;
    supplier: SupplierOption;
    branch: { id: number; name: string };
    expected_on: string | null;
    lines: number;
    lines_summary: string[];
    costs_visible: boolean;
    gross_minor?: number;
};

export type PurchaseLine = {
    id: number;
    line_no: number;
    item_type: string;
    item_id: number;
    name: Text;
    unit: string;
    quantity_milli: number;
    received_milli: number;
    accepted_milli: number;
    rejected_milli: number;
    remaining_milli: number;
    cancelled_milli: number;
    order_id: number | null;
    unit_cost_minor?: number | null;
    net_minor?: number | null;
    vat_minor?: number | null;
    gross_minor?: number | null;
};

export type ReceiptLine = {
    line_no: number | null;
    received_milli: number;
    accepted_milli: number;
    rejected_milli: number;
    reject_reason: string | null;
};

export type PurchaseReceipt = {
    id: number;
    received_on: string;
    location_id: number | null;
    reference: string | null;
    note: string | null;
    receiver: string | null;
    lines: ReceiptLine[];
    files: { uuid: string; name: string; url: string | null }[];
};

export type PurchaseDetail = {
    id: number;
    number: string;
    status: PurchaseStatus;
    supplier: { id: number; name: Text; active: boolean };
    branch: { id: number; name: string };
    location_id: number | null;
    expected_on: string | null;
    responsible: { id: number; name: string } | null;
    notes: string | null;
    ordered_at: string | null;
    cancel_reason: string | null;
    cancelled_at: string | null;
    lock_version: number;
    costs_visible: boolean;
    vat?: { rate_bp: number; inclusive: boolean };
    totals?: { net_minor: number; vat_minor: number; gross_minor: number };
    lines: PurchaseLine[];
    receipts: PurchaseReceipt[];
    can: {
        edit: boolean;
        confirm: boolean;
        receive: boolean;
        cancel: boolean;
        adjust: boolean;
    };
};

export type LocationOption = { id: number; name: string };

export type ItemResult = {
    item_type: string;
    item_id: number;
    name: Text;
    unit: string;
    tracked: boolean;
    code: string | null;
};

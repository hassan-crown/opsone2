import type { Meta, Option } from '@/components/customers/types';

export type { Meta, Option };

export type OrderKind = 'custom' | 'ready';

export type OrderRow = {
    id: number;
    number: string;
    kind: OrderKind;
    status: string;
    customer: { id: number; name: string };
    branch: { id: number; name: string };
    assignee: { id: number; name: string } | null;
    purchase_reference: string | null;
    created_at: string;
    financials_hidden: boolean;
    /** Present only with P-37; `null` is an unknown price (never 0). */
    total_minor?: number | null;
};

export type Localized = { ar?: string; en?: string; label?: string };

export type LineSnapshot = {
    version?: number;
    item?: {
        name?: Localized;
        product_name?: Localized;
        code?: string;
        category?: string | null;
        price_minor?: number;
        reference_price_minor?: number | null;
    };
    options: { key: string; label: Localized | string | null; value: string }[];
};

export type OrderLine = {
    id: number;
    position: number;
    type: 'custom_product' | 'custom_need' | 'ready_variant';
    catalog_version_id: number | null;
    snapshot: LineSnapshot;
    description: string | null;
    quantity: string;
    unit: string | null;
    removed: boolean;
    unit_price_minor?: number | null;
    line_total_minor?: number | null;
};

export type OrderDetail = OrderRow & {
    lock_version: number;
    customer_id: number;
    contact: { id: number; name: string; mobile: string } | null;
    need_summary: string | null;
    needs_measurement: boolean;
    needs_design: boolean;
    invoice_type: 'tax' | 'simplified' | null;
    related_orders: { id: number; number: string; kind: OrderKind }[];
    lines: OrderLine[];
    can: { edit: boolean; transfer_branch: boolean; sale: boolean };
};

export type HistoryEntry = {
    id: number;
    transition: string;
    from: string | null;
    to: string;
    actor: string | null;
    reason: string | null;
    at: string;
};

export type OptionGroup = {
    key: string;
    label: Localized;
    values?: Localized[];
};

export type CatalogItem = {
    id: number;
    type: 'custom_product' | 'ready_variant';
    name: string;
    version: number;
    category?: string | null;
    code?: string;
    option_groups?: OptionGroup[];
    /** Only with P-37 for the branch. */
    price_minor?: number;
    reference_price_minor?: number | null;
};

export type CustomerHit = {
    id: number;
    name: string;
    kind: 'person' | 'organization';
    mobile: string;
    branch_id: number;
    branch: string | null;
};

/** A line the user is composing; becomes the request payload. */
export type DraftLine = {
    key: string;
    type: 'custom_product' | 'custom_need' | 'ready_variant';
    product_id?: number;
    variant_id?: number;
    description?: string;
    quantity: string;
    unit?: string;
    options?: { key: string; value: string }[];
    /** Display only. */
    name: string;
    version?: number;
    price_minor?: number;
    optionsLabel?: string;
};

export function toPayload(line: DraftLine) {
    return {
        type: line.type,
        product_id: line.product_id,
        variant_id: line.variant_id,
        description: line.description,
        quantity: line.quantity,
        unit: line.unit || undefined,
        options: line.options?.length ? line.options : undefined,
    };
}

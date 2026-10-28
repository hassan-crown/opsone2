/** Shapes of the stock screens (P7a); quantities are decimal text in the item's unit, never floats. */

export type StockItemRef = {
    id: number;
    type: 'ready_variant' | 'material';
    item_id: number;
    name: string;
    code: string | null;
    unit: string;
    reorder_level: string | null;
};

export type Trackable = {
    type: 'ready_variant' | 'material';
    item_id: number;
    name: string;
    code: string | null;
    unit: string | null;
};

export type LocationRef = { id: number; code: string; name: string };

export type StockLocation = LocationRef & {
    name_i18n: { ar: string | null; en: string | null };
    branch: { id: number; name: string } | null;
    status: 'active' | 'disabled';
    is_default: boolean;
    manager: { id: number; name: string } | null;
    lock_version: number;
    can?: {
        edit: boolean;
        history: boolean;
        adjust: boolean;
        transfer: boolean;
    };
};

export type BalanceRow = {
    id: number;
    location: { id: number; code: string | null; name: string };
    item: StockItemRef;
    /** Null for a viewer who may see the available quantity only (P-48 sales/supervisor grant). */
    on_hand: string | null;
    reserved: string | null;
    available: string;
    incoming: string | null;
    low: boolean;
};

export type Reservation = {
    id: number;
    number: string;
    status:
        | 'active'
        | 'partially_issued'
        | 'issued'
        | 'released'
        | 'settled_mixed';
    location: LocationRef & { branch_id: number | null };
    item: StockItemRef;
    quantity: string;
    issued: string;
    released: string;
    remaining: string;
};

export type ReadyLineStock = {
    line_id: number;
    quantity: string;
    available: string;
    short: string;
    reserved: string;
    issued: string;
    released: string;
    reservations: Reservation[];
};

export type OrderStock =
    | {
          kind: 'ready';
          lines: ReadyLineStock[];
          locations: LocationRef[];
          can: { confirm: boolean; cancel: boolean };
      }
    | {
          kind: 'custom';
          open_reservations: number;
          can: { materials: boolean };
      };

export type Transfer = {
    id: number;
    number: string;
    status: 'draft' | 'in_transit' | 'received' | 'cancelled';
    from: LocationRef;
    to: LocationRef;
    note: string | null;
    lines: { id: number; item: StockItemRef; quantity: string }[];
    created_at: string;
    dispatched_at: string | null;
    received_at: string | null;
    lock_version: number;
    can_act: boolean;
};

export type CountLine = {
    id: number;
    item: StockItemRef;
    expected: string;
    counted: string;
    current: string;
    difference: string;
};

export type StockCount = {
    id: number;
    number: string;
    status: 'draft' | 'submitted' | 'approved' | 'rejected';
    location: LocationRef;
    reason: string;
    decision_note: string | null;
    created_by: string | null;
    decided_by: string | null;
    created_at: string;
    decided_at: string | null;
    lock_version: number;
    lines: CountLine[];
    can: { edit: boolean; decide: boolean };
};

export type Movement = {
    id: number;
    kind: string;
    location: { id: number; code: string };
    item: StockItemRef;
    quantity: string;
    on_hand_delta: string;
    reserved_delta: string;
    on_hand_after: string;
    reserved_after: string;
    document_number: string | null;
    order_id: number | null;
    source: { type: string; id: number | null } | null;
    reason: string | null;
    actor: string | null;
    occurred_at: string;
};

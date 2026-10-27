export type Option = { id: number; name: string };

export type Meta = {
    page: number;
    last_page: number;
    total: number;
    per_page: number;
    from: number | null;
    to: number | null;
};

export type CustomerRow = {
    id: number;
    kind: 'person' | 'organization';
    name: string;
    mobile: string;
    email: string | null;
    branch: string | null;
    assignee: string | null;
    created_at: string | null;
};

/** Sensitive keys are present only when the server's Gate lets the viewer see them. */
export type CustomerDetail = CustomerRow & {
    branch_id: number;
    assignee_id: number | null;
    country: string;
    portal_login_enabled: boolean;
    preferred_channel: 'whatsapp' | 'phone' | 'email' | null;
    internal_notes?: string | null;
    commercial_registration?: string | null;
    vat_number?: string | null;
    office_phone?: string | null;
};

export type Contact = {
    id: number;
    name: string;
    mobile: string;
    job_title: string | null;
    email: string | null;
    note: string | null;
    is_primary: boolean;
};

export type InterestStatus = 'new' | 'following' | 'converted' | 'closed';

export type InterestRow = {
    id: number;
    reference: string;
    status: InterestStatus;
    name: string;
    mobile: string | null;
    email: string | null;
    need: string;
    channel: 'phone' | 'whatsapp' | 'email' | 'walk_in' | 'website' | 'other';
    branch: string | null;
    branch_id: number;
    assignee: string | null;
    assignee_id: number | null;
    customer_id: number | null;
    converted_customer_id: number | null;
    close_reason: string | null;
    close_note: string | null;
    created_at: string | null;
};

export type InterestCan = {
    edit: boolean;
    follow: boolean;
    convert: boolean;
    close: boolean;
    reopen: boolean;
};

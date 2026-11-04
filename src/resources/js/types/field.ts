export type InstallationStatus =
    | 'not_started'
    | 'in_progress'
    | 'finished'
    | 'finished_with_remarks';

export type FieldVisitRow = {
    id: number;
    /** installation (ADR-0036) or a sales kind — visit | measurement (ADR-0041). */
    kind?: string;
    date: string;
    start: string;
    end: string;
    appointment_status: string;
    status: InstallationStatus;
    order: { id: number; number: string };
    customer: { name: string; phone: string; contact: string | null };
    address: string;
    maps_url: string;
    items: { name: string; quantity: string; unit: string | null }[];
    notes: string | null;
    team: string[];
};

export type RemarkKind = 'missing_part' | 'damage' | 'extra_work' | 'other';

export type InstallationRemark = {
    id: number;
    kind: RemarkKind;
    description: string;
    status: 'open' | 'resolved';
    recorded_by: string | null;
    follow_up: string | null;
    resolution: string | null;
    resolved_by: string | null;
    lock_version: number;
    created_at: string;
};

export type FieldVisitDetail = FieldVisitRow & {
    visit: {
        id: number;
        lock_version: number;
        arrived_at: string;
        located: boolean;
        finished_at: string | null;
        duration_minutes: number | null;
        signed: boolean;
        signer_name: string | null;
        signature_method: 'device' | 'portal' | null;
        signed_at: string | null;
        signature_url: string | null;
        signature_requested: boolean;
    } | null;
    photos: {
        id: number;
        phase: 'before' | 'after';
        client_uuid: string;
        url: string;
    }[];
    remarks: InstallationRemark[];
    min_photos: number;
};

export type OrderInstallation = {
    can_resolve: boolean;
    visits: {
        appointment_id: number;
        date: string;
        start: string;
        team: string[];
        status: InstallationStatus;
        arrived_at: string | null;
        finished_at: string | null;
        duration_minutes: number | null;
        signature: {
            name: string | null;
            method: 'device' | 'portal' | null;
            signed_at: string;
            url: string | null;
        } | null;
        photos: { id: number; phase: 'before' | 'after'; url: string }[];
        photo_count: number;
        remarks: InstallationRemark[];
    }[];
};

export const STATUS_VARIANT: Record<
    InstallationStatus,
    'neutral' | 'info' | 'success' | 'warning'
> = {
    not_started: 'neutral',
    in_progress: 'info',
    finished: 'success',
    finished_with_remarks: 'warning',
};

/** ADR-0041: what the field app lists for a field sales rep. */
export type FieldDue = { due_at: string | null; overdue: boolean };

export type FieldLeadRow = FieldDue & {
    id: number;
    reference: string | null;
    status: string;
    name: string;
    mobile: string | null;
    need: string;
    channel: string;
    branch: string;
    note: string | null;
};

export type FieldRequestRow = FieldDue & {
    id: number;
    reference: string | null;
    kind: string;
    name: string;
    phone: string;
    city: string | null;
    address: string | null;
    note: string | null;
    preferred_from: string | null;
    preferred_to: string | null;
    time_of_day: string | null;
};

export type FieldWork = { leads: FieldLeadRow[]; requests: FieldRequestRow[] };

export type FieldLeadDetail = FieldLeadRow & {
    email: string | null;
    close_reason: string | null;
    converted_order_id: number | null;
    can: { follow: boolean; close: boolean; convert: boolean };
};

export type SalesVisitDetail = Omit<FieldVisitRow, 'order'> & {
    kind: string;
    order: { id: number; number: string; channel: string };
    visit: {
        id: number;
        lock_version: number;
        note: string | null;
        outcome: string | null;
        arrived_at: string;
        finished_at: string | null;
    } | null;
    photos: { id: number; client_uuid: string; url: string }[];
    max_photos: number;
    measurements: {
        id: number;
        location: string;
        name: string;
        value: string;
        unit: string;
        kind: string;
    }[];
    units: string[];
    documents: {
        id: number;
        kind: 'quote' | 'contract';
        number: string;
        status: string;
        lock_version: number;
        valid_until: string | null;
        can_send: boolean;
        can_sign_link: boolean;
    }[];
    lead: { id: number; reference: string | null } | null;
    customer_portal: boolean;
    can: {
        start: boolean;
        work: boolean;
        measure: boolean;
        create_quote: boolean;
        edit_quote: boolean;
        portal_invite: boolean;
    };
};

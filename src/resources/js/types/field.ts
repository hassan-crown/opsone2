export type InstallationStatus =
    | 'not_started'
    | 'in_progress'
    | 'finished'
    | 'finished_with_remarks';

export type FieldVisitRow = {
    id: number;
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

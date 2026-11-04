export type AppointmentRequestStatus =
    | 'new'
    | 'assigned'
    | 'scheduled'
    | 'closed';

export type FieldMode = 'off' | 'optional' | 'required';

export type AppointmentRequestRow = {
    id: number;
    reference: string | null;
    status: AppointmentRequestStatus;
    source: 'site' | 'portal';
    kind: string;
    preferred_from: string | null;
    preferred_to: string | null;
    time_of_day: string | null;
    name: string;
    phone: string;
    city: string | null;
    branch: string;
    assignee: { id: number; name: string } | null;
    due_at: string | null;
    overdue: boolean;
    created_at: string;
};

export type AppointmentRequestDetail = AppointmentRequestRow & {
    address: string | null;
    note: string | null;
    lock_version: number;
    customer: { id: number; name: string } | null;
    interest: { id: number; reference: string | null } | null;
    order: { id: number; number: string } | null;
    appointment_id: number | null;
    scheduled_at: string | null;
    close_reason: string | null;
    close_note: string | null;
    closed_at: string | null;
};

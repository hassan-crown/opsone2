export type TaskStatus =
    | 'unassigned'
    | 'assigned'
    | 'in_progress'
    | 'waiting'
    | 'completed'
    | 'cancelled';

export type TaskCan = {
    claim: boolean;
    assign: boolean;
    reassign: boolean;
    start: boolean;
    wait: boolean;
    resolve: boolean;
    complete: boolean;
    cancel: boolean;
    measure: boolean;
};

export type TaskRow = {
    id: number;
    title: string;
    status: TaskStatus;
    kind: string;
    order: {
        id: number;
        number: string;
        customer: string;
        viewable: boolean;
    };
    stage: string;
    department: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    mine: boolean;
    due_at: string | null;
    overdue: boolean;
    waiting_need: string | null;
    waiting_follow_up_on: string | null;
    completed_at: string | null;
    lock_version: number;
    can: TaskCan;
    visit?: { starts_at: string; address: string; maps_url: string } | null;
};

export type Measurement = {
    id: number;
    order_line_id: number | null;
    location: string;
    name: string;
    value: string;
    unit: string;
    kind: 'estimated' | 'final';
    measured_on: string;
    note: string | null;
};

export type TaskDetail = TaskRow & {
    action: {
        type: string;
        name: string;
        completion: string;
        requires_attachment: boolean;
        measurement_kind: 'estimated' | 'final';
    } | null;
    result_note: string | null;
    lines: {
        id: number;
        name: string;
        quantity: string;
        unit: string | null;
    }[];
    attachments: {
        uuid: string;
        name: string;
        size_bytes: number;
        url: string;
    }[];
    can_attach: boolean;
    measurements: Measurement[];
    visit: {
        id: number;
        kind: string;
        status: string;
        starts_at: string;
        ends_at: string;
        address: string;
        maps_url: string;
        customer: string;
        members: string[];
    } | null;
    follow_up: string | null;
};

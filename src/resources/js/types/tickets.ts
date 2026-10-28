/** Ticket payloads (App\Tickets\TicketPresenter). The customer shapes carry no staff data by construction. */

export type TicketStatus =
    | 'new'
    | 'in_progress'
    | 'awaiting_customer'
    | 'resolved'
    | 'closed';

export type TicketType = 'issue' | 'question' | 'request' | 'other';

export type TicketFile = { name: string; size_bytes: number; url: string };

export type TicketOrderRef = { id: number; number: string } | null;

/** Customer workspace (public messages only). */
export type CustomerTicketRow = {
    id: number;
    number: string;
    subject: string;
    type: TicketType;
    status: TicketStatus;
    order: TicketOrderRef;
    created_at: string;
    updated_at: string | null;
};

export type CustomerTicketMessage = {
    id: number;
    mine: boolean;
    body: string;
    created_at: string;
    files: TicketFile[];
};

export type CustomerTicket = CustomerTicketRow & {
    messages: CustomerTicketMessage[];
    can_close: boolean;
    reopens_on_reply: boolean;
};

/** Staff (P-59): the conversation includes internal notes. */
export type StaffTicketRow = {
    id: number;
    number: string;
    subject: string;
    type: TicketType;
    status: TicketStatus;
    customer: string;
    order: TicketOrderRef;
    assignee: { id: number; name: string } | null;
    created_at: string;
    waiting_since: string | null;
};

export type StaffTicketMessage = {
    id: number;
    visibility: 'public' | 'internal';
    author_kind: 'customer' | 'staff';
    author: string | null;
    body: string;
    created_at: string;
    files: TicketFile[];
};

export type StaffTicket = StaffTicketRow & {
    resolution: string | null;
    messages: StaffTicketMessage[];
    assignable: { id: number; name: string }[];
    can: {
        take: boolean;
        public_reply: boolean;
        request_info: boolean;
        resolve: boolean;
        close: boolean;
        reopen: boolean;
        assign: boolean;
    };
};

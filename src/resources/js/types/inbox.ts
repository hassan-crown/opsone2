export type InboxTemplate = {
    name: string;
    language: string;
    params: number;
    label: string;
};

export type InboxSettingsProps = {
    enabled: boolean;
    default_branch_id: number | null;
    templates: InboxTemplate[];
    webhook_url: string;
};

export type ConversationRow = {
    id: number;
    name: string | null;
    wa_id: string;
    known: boolean;
    branch: string | null;
    assignee: { id: number; name: string } | null;
    unread: number;
    window_open: boolean;
    last_message_at: string | null;
};

export type ConversationMessageView = {
    id: number;
    direction: 'in' | 'out';
    type: string;
    body: string | null;
    template: string | null;
    params: string[] | null;
    status: string;
    error: string | null;
    media_status: 'pending' | 'stored' | 'rejected' | 'failed' | null;
    file: {
        name: string;
        mime: string;
        size_bytes: number;
        url: string;
    } | null;
    sender: string | null;
    ai_intent: string | null;
    ai_suspicious: boolean;
    from_suggestion: boolean;
    occurred_at: string;
};

/** AI-4: the current staff member's own reply suggestion and the conversation's classification/flag. */
export type InboxAiPanel = {
    available: boolean;
    flagged: boolean;
    intent: string | null;
    suggestion: {
        id: number;
        reply: string;
        facts: { key: string; value: string | number | boolean }[];
        created_at: string | null;
    } | null;
};

/** AI-7a: the new-lead assistant (draft, extracted qualification fields, server-computed measurement slots). */
export type LeadFieldKey =
    | 'name'
    | 'email'
    | 'product'
    | 'district'
    | 'budget'
    | 'preferred_time';

export type InboxLeadPanel =
    | { available: false }
    | {
          available: true;
          can_accept: boolean;
          suggestion: {
              id: number;
              reply: string;
              fields: Partial<Record<LeadFieldKey, string | null>>;
          } | null;
          slots: {
              starts_at: string;
              ends_at: string;
              member_id: number;
              label: string;
          }[];
          slots_text: string | null;
          orders: { id: number; number: string }[];
      };

export type ConversationDetail = ConversationRow & {
    window_closes_at: string | null;
    customer: {
        id: number;
        name: string;
        url: string | null;
        opted_out: boolean;
    } | null;
    messages: ConversationMessageView[];
    templates: InboxTemplate[];
    configured: boolean;
    can: { reply: boolean; assign: boolean; link: boolean };
    assignable: { id: number; name: string }[];
    customer_results: { id: number; name: string; mobile: string | null }[];
    ai: InboxAiPanel;
    lead: InboxLeadPanel;
};

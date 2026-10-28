export type Localized = { ar: string; en: string | null };

export type TargetType = 'order_assignee' | 'member' | 'department';
export type Target = { type: TargetType; id?: number };

export type ActionTypeKey =
    | 'capture_needs'
    | 'record_measurements'
    | 'prepare_design'
    | 'prepare_document'
    | 'customer_approval'
    | 'work_task'
    | 'supply_follow_up'
    | 'payment_check'
    | 'required_document'
    | 'record_delivery';

export type GroupKey =
    | 'info_measurement'
    | 'agreement_approval'
    | 'execution_supply'
    | 'finance'
    | 'delivery';

export type Requirement = 'required' | 'optional';
export type AppliesWhen = 'always' | 'needs_measurement' | 'needs_design';

export type ActionDef = {
    key: string;
    type: ActionTypeKey;
    name: Localized;
    requirement: Requirement;
    applies_when: AppliesWhen;
    assign: Target;
    inputs: Record<string, unknown>;
    after: string | null;
};

export type StageDef = {
    key: string;
    name: Localized;
    public_name: Localized | null;
    show_to_customer: boolean;
    follow_up: Target;
    target_days: number | null;
    actions: ActionDef[];
};

export type Definition = { stages: StageDef[] };

export type CatalogueEntry = {
    type: ActionTypeKey;
    group: GroupKey;
    completion: string;
    label: { ar: string; en: string };
    defaults: Record<string, unknown>;
};

export type FlowOptions = {
    catalogue: CatalogueEntry[];
    groups: GroupKey[];
    templates: {
        key: string;
        label: { ar: string; en: string };
        stages: number;
    }[];
    members: { id: number; name: string }[];
    departments: { id: number; name: string }[];
};

export type FlowIssue = {
    code: string;
    path: string;
    stage: string | null;
    action: string | null;
    message: string;
};

export type FlowState = {
    status: 'published' | 'published_with_draft';
    lock_version: number;
    template_key: string;
    version: { number: number; published_at: string };
    published: Definition;
    draft: Definition | null;
    draft_by: string | null;
    draft_updated_at: string | null;
};

export type FlowVersionRow = {
    number: number;
    published_at: string;
    running_orders: number;
};

/** Order progress read model (`WorkflowPresenter::order`). */
export type ProgressTask = {
    id: number;
    kind: string;
    status: string;
    assignee_id: number | null;
    department_id: number | null;
};

export type ProgressAction = {
    id: number;
    key: string;
    type: ActionTypeKey;
    group: GroupKey;
    completion: string;
    name: string;
    requirement: Requirement;
    applies_when: AppliesWhen;
    status: string;
    held: boolean;
    allowed_reason: string | null;
    rework_count: number;
    has_source: boolean;
    tasks: ProgressTask[];
    can: { allow: boolean; rework: boolean };
};

export type ProgressStage = {
    id: number;
    key: string;
    position: number;
    name: string;
    public_name: string;
    show_to_customer: boolean;
    status: string;
    follow_up: {
        type: string;
        user_id: number | null;
        department_id: number | null;
    };
    target_days: number | null;
    due_on: string | null;
    started_at: string | null;
    completed_at: string | null;
    can_complete: boolean;
    actions: ProgressAction[];
};

export type Blocker = {
    action: string;
    name: string;
    type: ActionTypeKey;
    status: string;
    held: boolean;
    responsible_user_id: number | null;
};

export type Progress = {
    flow_version: number;
    paused: boolean;
    pause: {
        reason: string;
        follow_up_user_id: number | null;
        review_on: string | null;
    } | null;
    active_stage_id: number | null;
    lock_version: number;
    can: { pause: boolean; edit_upcoming: boolean; finish_execution: boolean };
    stages: ProgressStage[];
    blockers: Blocker[];
};

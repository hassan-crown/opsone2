export type NotificationItem = {
    id: number;
    /** Translation keys; text is rendered in the viewer's locale. */
    title: string;
    body: string | null;
    params: Record<string, string | number>;
    read: boolean;
    /** Whether the notification leads somewhere; access is re-checked when it is opened. */
    linked: boolean;
    created_at: string | null;
};

export type NotificationFeed = {
    unread: number;
    items: NotificationItem[];
};

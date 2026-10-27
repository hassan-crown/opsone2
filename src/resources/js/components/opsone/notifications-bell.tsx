import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import NotificationController from '@/actions/App/Http/Controllers/Notifications/NotificationController';
import NotificationPreferenceController from '@/actions/App/Http/Controllers/Notifications/NotificationPreferenceController';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { NotificationItem } from '@/types/notifications';

/** Light polling instead of a socket (Q-26): the bell data is a partial reload of one shared prop. */
const POLL_MS = 60_000;

function Row({ item, onOpen }: { item: NotificationItem; onOpen: () => void }) {
    const t = useT();
    const { locale } = useLocale();
    const title = t(item.title as TranslationKey, item.params);
    const body = item.body ? t(item.body as TranslationKey, item.params) : null;

    const content = (
        <>
            <span
                aria-hidden
                className={cn(
                    'mt-2 size-2 shrink-0 rounded-full',
                    item.read ? 'bg-transparent' : 'bg-brand',
                )}
            />
            <span className="flex min-w-0 flex-1 flex-col gap-1">
                <span
                    className={cn(
                        'text-body text-strong',
                        !item.read && 'font-semibold',
                    )}
                >
                    {title}
                </span>
                {body && <span className="text-body text-weak">{body}</span>}
                {item.created_at && (
                    <span className="text-caption text-weak">
                        {formatDateTime(item.created_at, locale)}
                    </span>
                )}
            </span>
        </>
    );
    const className =
        'flex w-full min-h-11 items-start gap-3 rounded-md px-3 py-3 text-start text-inherit no-underline hover:bg-hover';

    // Opening goes through the server, which re-checks access to the target every time.
    return item.linked ? (
        <Link
            href={NotificationController.open.url(item.id)}
            className={className}
            onClick={onOpen}
        >
            {content}
        </Link>
    ) : (
        <button
            type="button"
            className={className}
            onClick={() => {
                if (!item.read) {
                    router.post(
                        NotificationController.read.url(item.id),
                        {},
                        { preserveScroll: true, only: ['notifications'] },
                    );
                }
            }}
        >
            {content}
        </button>
    );
}

export function NotificationsBell() {
    const t = useT();
    const { notifications } = usePage().props;
    const [open, setOpen] = useState(false);

    usePoll(POLL_MS, { only: ['notifications'] });

    if (!notifications) {
        return null;
    }

    const unread = notifications.unread;

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="relative text-strong"
                aria-label={
                    unread > 0
                        ? `${t('notifications.bell')} — ${t('notifications.unread', { count: unread })}`
                        : t('notifications.bell')
                }
                data-test="notifications-bell"
                onClick={() => setOpen(true)}
            >
                <Icon icon={Bell} />
                {unread > 0 && (
                    <span
                        aria-hidden
                        className="absolute end-1 top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] leading-4 font-semibold text-on-brand"
                    >
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </Button>

            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent side="end" className="flex flex-col gap-0 p-0">
                    <div className="border-b border-stroke-weak p-4 pe-12">
                        <SheetTitle>{t('notifications.title')}</SheetTitle>
                        <SheetDescription className="mt-1">
                            {t('notifications.unread', { count: unread })}
                        </SheetDescription>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={unread === 0}
                                onClick={() =>
                                    router.post(
                                        NotificationController.readAll.url(),
                                        {},
                                        {
                                            preserveScroll: true,
                                            only: ['notifications'],
                                        },
                                    )
                                }
                            >
                                {t('notifications.mark_all')}
                            </Button>
                            <Button asChild variant="ghost" size="sm">
                                <Link
                                    href={NotificationPreferenceController.edit.url()}
                                    onClick={() => setOpen(false)}
                                >
                                    {t('notifications.preferences_link')}
                                </Link>
                            </Button>
                        </div>
                    </div>
                    <div className="flex-1 overflow-y-auto p-2">
                        {notifications.items.length === 0 ? (
                            <p className="p-4 text-body text-weak">
                                {t('notifications.empty')}
                            </p>
                        ) : (
                            <ul className="m-0 flex list-none flex-col p-0">
                                {notifications.items.map((item) => (
                                    <li key={item.id}>
                                        <Row
                                            item={item}
                                            onOpen={() => setOpen(false)}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}

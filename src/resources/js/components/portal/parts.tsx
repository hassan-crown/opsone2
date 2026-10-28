import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    ChevronRight,
    FileCheck,
    Paperclip,
    Receipt,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Amount } from '@/components/opsone/amount';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showDocument } from '@/routes/customer/documents';
import { pay, show as showOrder } from '@/routes/customer/orders';
import type {
    PortalFile,
    PortalOrder,
    PortalRequired,
    PortalUpdate,
} from '@/types/portal';

type Tone = 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const TONES: Record<string, Tone> = {
    in_progress: 'info',
    paused: 'warning',
    execution_finished: 'info',
    cancellation_pending: 'warning',
    cancelled: 'neutral',
    closed: 'success',
    awaiting_payment: 'warning',
    needs_attention: 'warning',
    confirmed: 'success',
    partially_delivered: 'info',
    delivered: 'success',
    awaiting_customer: 'warning',
    approved: 'success',
    changes_requested: 'info',
    expired: 'neutral',
    replaced: 'neutral',
    under_review: 'info',
    needs_correction: 'danger',
    awaiting_confirmation: 'warning',
    done: 'success',
    pending: 'warning',
    accepted: 'success',
    rejected: 'danger',
    withdrawn: 'neutral',
    upcoming: 'neutral',
    due: 'warning',
    overdue: 'danger',
    partially_paid: 'info',
    paid: 'success',
};

/** A status in customer words (`portal.status.{scope}.{status}`). */
export function StatusBadge({
    scope,
    status,
}: {
    scope:
        | 'order'
        | 'document'
        | 'proof'
        | 'appointment'
        | 'cancellation'
        | 'due';
    status: string;
}) {
    const t = useT();

    return (
        <Badge variant={TONES[status] ?? 'neutral'}>
            {t(`portal.status.${scope}.${status}` as TranslationKey)}
        </Badge>
    );
}

export function Section({
    title,
    action,
    children,
    className,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('mb-8', className)}>
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="text-subtitle text-strong">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

export function DateText({
    value,
    time = false,
}: {
    value: string | null | undefined;
    time?: boolean;
}) {
    const { locale } = useLocale();
    if (!value) {
        return null;
    }

    return (
        <time dateTime={value}>
            {time ? formatDateTime(value, locale) : formatDate(value, locale)}
        </time>
    );
}

/** «Open» chevron; the Icon component mirrors it in RTL. */
export function Forward() {
    return <Icon icon={ChevronRight} />;
}

export function OrderRow({ order }: { order: PortalOrder }) {
    const t = useT();

    return (
        <Link
            href={showOrder(order.id).url}
            className="flex items-center gap-3 rounded-lg border border-stroke-weak bg-card p-4 hover:bg-hover"
        >
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <bdi className="text-label text-strong" dir="ltr">
                        {order.number}
                    </bdi>
                    <StatusBadge scope="order" status={order.status} />
                </div>
                <p className="mt-1 truncate text-body text-weak">
                    {[
                        order.title,
                        t(`orders.kind.${order.kind}` as TranslationKey),
                    ]
                        .filter(Boolean)
                        .join(t('portal.sep'))}
                </p>
                {order.stage && (
                    <p className="mt-1 text-caption text-weak">
                        {t('portal.orders.stage', { stage: order.stage })}
                    </p>
                )}
            </div>
            <Forward />
        </Link>
    );
}

/** One «مطلوب منك» item with its direct action (WEB-03). */
export function RequiredItem({ item }: { item: PortalRequired }) {
    const t = useT();
    const { locale } = useLocale();
    const parts = {
        document: {
            icon: FileCheck,
            title: t(`portal.required.document.${item.kind}` as TranslationKey),
            href: showDocument(item.id).url,
            action: t('portal.required.review'),
        },
        appointment: {
            icon: CalendarClock,
            title: t('portal.required.appointment'),
            href: showOrder(item.order_id).url,
            action: t('portal.required.confirm'),
        },
        proof: {
            icon: Receipt,
            title: t('portal.required.proof'),
            href: pay(item.order_id, { query: { replaces: item.id } }).url,
            action: t('portal.required.resend'),
        },
        payment: {
            icon: Wallet,
            title: t('portal.required.payment'),
            href: pay(item.order_id).url,
            action: t('portal.required.pay'),
        },
    }[item.type];

    return (
        <li className="flex flex-col gap-3 rounded-lg border border-stroke-weak bg-card p-4 sm:flex-row sm:items-center">
            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-brand-subtle-text">
                <Icon icon={parts.icon} />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-label text-strong">
                    {parts.title}
                    {item.type === 'payment' && item.amount_minor ? (
                        <>
                            {' '}
                            <Amount value={item.amount_minor / 100} strong />
                        </>
                    ) : null}
                </p>
                <p className="mt-1 text-caption text-weak">
                    <bdi dir="ltr">{item.order}</bdi>
                    {item.starts_at && (
                        <>
                            {t('portal.sep')}
                            <DateText value={item.starts_at} time />
                        </>
                    )}
                    {item.reason && (
                        <>
                            {t('portal.sep')}
                            {item.reason}
                        </>
                    )}
                    {item.type === 'payment' && item.pending_minor ? (
                        <>
                            {t('portal.sep')}
                            {t('portal.required.pending', {
                                amount: new Intl.NumberFormat('en-US', {
                                    minimumFractionDigits: 2,
                                }).format(item.pending_minor / 100),
                            })}
                        </>
                    ) : null}
                </p>
            </div>
            <Link
                href={parts.href}
                lang={locale}
                className={buttonVariants({ variant: 'default' })}
            >
                {parts.action}
            </Link>
        </li>
    );
}

export function UpdateItem({ update }: { update: PortalUpdate }) {
    const t = useT();
    const href =
        update.link[0] === 'document'
            ? showDocument(update.link[1]).url
            : showOrder(update.link[1]).url;

    return (
        <li>
            <Link
                href={href}
                className="flex items-start gap-3 rounded-md px-2 py-3 hover:bg-hover"
            >
                <div className="min-w-0 flex-1">
                    <p className="text-body text-strong">
                        {t(`portal.update.${update.type}` as TranslationKey, {
                            ref: update.ref ?? '',
                            kind: update.kind
                                ? t(
                                      `portal.document.kind.${update.kind}` as TranslationKey,
                                  )
                                : '',
                        })}
                        {update.amount_minor ? (
                            <>
                                {' '}
                                <Amount value={update.amount_minor / 100} />
                            </>
                        ) : null}
                    </p>
                    <p className="mt-1 text-caption text-weak">
                        <bdi dir="ltr">{update.order}</bdi>
                        {t('portal.sep')}
                        <DateText value={update.at} />
                    </p>
                </div>
            </Link>
        </li>
    );
}

export function FileLinks({ files }: { files: PortalFile[] }) {
    if (files.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-1">
            {files.map((file) => (
                <li key={file.url}>
                    <a
                        href={file.url}
                        className="inline-flex items-center gap-1 text-label text-brand underline-offset-2 hover:underline"
                    >
                        <Icon icon={Paperclip} size="sm" />
                        <bdi>{file.name}</bdi>
                    </a>
                </li>
            ))}
        </ul>
    );
}

export function Money({ minor }: { minor: number | null | undefined }) {
    const t = useT();

    return minor === null || minor === undefined ? (
        <span className="text-weak">{t('portal.money.unknown')}</span>
    ) : (
        <Amount value={minor / 100} />
    );
}

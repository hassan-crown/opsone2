import { Link, router } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import SupplierController from '@/actions/App/Http/Controllers/Procurement/SupplierController';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatQty } from '@/components/procurement/quantity';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PurchaseStatus } from '@/types/procurement';

const STATUS_VARIANT = {
    draft: 'warning',
    ordered: 'info',
    partially_received: 'brand',
    received: 'success',
    cancelled_remainder: 'neutral',
} as const;

export function PurchaseStatusBadge({ status }: { status: PurchaseStatus }) {
    const t = useT();

    return (
        <Badge variant={STATUS_VARIANT[status]}>
            {t(`procurement.status.${status}` as TranslationKey)}
        </Badge>
    );
}

export function unitLabel(
    unit: string,
    t: (key: TranslationKey) => string,
): string {
    return unit === 'piece' ? t('procurement.unit.piece') : unit;
}

/** Section tabs between purchase orders and suppliers (real links). */
export function ProcurementTabs({
    active,
    suppliers = true,
}: {
    active: 'purchases' | 'suppliers';
    suppliers?: boolean;
}) {
    const t = useT();
    const items = [
        {
            key: 'purchases' as const,
            href: PurchaseController.index.url(),
            label: t('procurement.tab_purchases'),
        },
        ...(suppliers
            ? [
                  {
                      key: 'suppliers' as const,
                      href: SupplierController.index.url(),
                      label: t('procurement.tab_suppliers'),
                  },
              ]
            : []),
    ];

    return (
        <nav
            aria-label={t('procurement.tabs')}
            className="mb-4 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b border-stroke-weak"
        >
            {items.map((item) => (
                <Link
                    key={item.key}
                    href={item.href}
                    aria-current={item.key === active ? 'page' : undefined}
                    className={cn(
                        'relative inline-flex min-h-11 shrink-0 items-center rounded-t-md px-4 text-control text-weak hover:bg-hover hover:text-strong focus-visible:-outline-offset-2',
                        item.key === active &&
                            'font-semibold text-strong after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-xs after:bg-brand',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}

/** A fresh idempotency key per form mount; `renew` after a failed submit so an edited payload never reuses it. */
export function useIdempotencyKey(): { key: string; renew: () => void } {
    const make = () =>
        (typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : `${Date.now()}${Math.random().toString(36).slice(2)}`
        ).replaceAll('-', '');
    const [key, setKey] = useState(make);

    return { key, renew: () => setKey(make()) };
}

/** Prominent notice for a stale (409) or refused transition, with a reload button. */
export function FormErrors({
    errors,
}: {
    errors: Record<string, string | undefined>;
}) {
    const t = useT();
    const stale = errors.lock_version;
    const other = errors.transition ?? errors.idempotency_key;

    if (!stale && !other) {
        return null;
    }

    return (
        <div className="grid gap-3">
            {stale && (
                <Alert variant="danger" role="alert" data-test="stale-conflict">
                    <AlertBody>
                        <AlertTitle>
                            {t('procurement.conflict_title')}
                        </AlertTitle>
                        <p>{stale}</p>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="mt-3"
                            onClick={() => router.reload()}
                        >
                            <Icon icon={RefreshCw} />
                            {t('procurement.reload')}
                        </Button>
                    </AlertBody>
                </Alert>
            )}
            {other && (
                <Alert variant="danger" role="alert" data-test="form-error">
                    <AlertBody>{other}</AlertBody>
                </Alert>
            )}
        </div>
    );
}

export function BackLink({ href, label }: { href: string; label: string }) {
    return (
        <Link
            href={href}
            className="inline-flex min-h-11 items-center gap-1 text-link hover:underline"
        >
            <Icon icon={ArrowLeft} size="sm" />
            {label}
        </Link>
    );
}

/** A quantity (integer thousandths) with its unit; the figure keeps left-to-right digits in both languages. */
export function Qty({ milli, unit }: { milli: number; unit: string }) {
    const t = useT();

    return (
        <span className="whitespace-nowrap tabular-nums">
            <bdi dir="ltr">{formatQty(milli)}</bdi>
            {unit ? ` ${unitLabel(unit, t)}` : ''}
        </span>
    );
}

import { Link } from '@inertiajs/react';
import { useState } from 'react';
import StockController from '@/actions/App/Http/Controllers/Inventory/StockController';
import StockCountController from '@/actions/App/Http/Controllers/Inventory/StockCountController';
import StockLocationController from '@/actions/App/Http/Controllers/Inventory/StockLocationController';
import StockTransferController from '@/actions/App/Http/Controllers/Inventory/StockTransferController';
import { Badge } from '@/components/ui/badge';
import { api } from '@/lib/api';
import type { ApiResult } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslateFn, TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type StockTab =
    | 'balances'
    | 'movements'
    | 'transfers'
    | 'counts'
    | 'ready'
    | 'locations';

/** Real links between the stock screens (StockBalances, StockMovements, StockTransfer, StockCount, ReadyStock, StockLocations). */
export function StockTabs({
    active,
    history = true,
}: {
    active: StockTab;
    history?: boolean;
}) {
    const t = useT();
    const tabs: { key: StockTab; href: string }[] = [
        { key: 'balances', href: StockController.index.url() },
        ...(history
            ? [{ key: 'movements' as const, href: StockController.movements.url() }]
            : []),
        { key: 'transfers', href: StockTransferController.index.url() },
        { key: 'counts', href: StockCountController.index.url() },
        { key: 'ready', href: StockController.ready.url() },
        { key: 'locations', href: StockLocationController.index.url() },
    ];

    return (
        <nav
            aria-label={t('stock.tabs')}
            className="mb-4 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b border-stroke-weak"
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={tab.key === active ? 'page' : undefined}
                    className={cn(
                        'relative inline-flex min-h-11 shrink-0 items-center gap-2 rounded-t-md px-4 text-control text-weak hover:bg-hover hover:text-strong focus-visible:-outline-offset-2',
                        tab.key === active &&
                            'font-semibold text-strong after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-xs after:bg-brand',
                    )}
                >
                    {t(`stock.tab.${tab.key}` as TranslationKey)}
                </Link>
            ))}
        </nav>
    );
}

/** A quantity with its unit: digits stay LTR inside RTL text. */
export function Qty({
    value,
    unit,
    strong,
}: {
    value: string;
    unit?: string | null;
    strong?: boolean;
}) {
    const t = useT();

    return (
        <span className={cn('whitespace-nowrap', strong && 'font-semibold')}>
            <bdi dir="ltr">{value}</bdi>
            {unit && (
                <span className="ms-1 text-caption text-weak">
                    {t(`stock.unit.${unit}` as TranslationKey)}
                </span>
            )}
        </span>
    );
}

const STATUS_TONE: Record<
    string,
    'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
    active: 'info',
    partially_issued: 'warning',
    issued: 'success',
    released: 'neutral',
    settled_mixed: 'neutral',
    draft: 'neutral',
    in_transit: 'warning',
    received: 'success',
    cancelled: 'neutral',
    submitted: 'info',
    approved: 'success',
    rejected: 'danger',
    disabled: 'neutral',
};

export function StockStatus({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge variant={STATUS_TONE[status] ?? 'neutral'}>
            {t(`stock.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** A server refusal in the user's language. */
export function explainStock(result: ApiResult<unknown>, t: TranslateFn): string {
    if (result.stale) {
        return t('stock.error.stale');
    }
    if (result.status === 403) {
        return t('stock.error.forbidden');
    }
    if (result.status === 409) {
        return t('stock.error.state');
    }

    return (
        Object.values(result.errors)[0]?.[0] ??
        result.message ??
        t('stock.error.failed')
    );
}

/** One stock write: busy state, field errors and a readable refusal. */
export function useStockCall<T = unknown>() {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const run = async (
        method: 'POST' | 'PUT',
        url: string,
        body: Record<string, unknown>,
    ): Promise<T | null> => {
        setBusy(true);
        setError(null);
        setErrors({});
        const result = await api<T>(method, url, body);
        setBusy(false);
        if (result.ok && result.data) {
            return result.data;
        }
        setErrors(result.errors);
        setError(explainStock(result, t));

        return null;
    };

    const fieldError = (field: string): string | undefined =>
        errors[field]?.[0];

    return { busy, error, errors, fieldError, run, setError };
}

export function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

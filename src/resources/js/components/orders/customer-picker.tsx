import { useEffect, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { Field } from '@/components/opsone/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { CustomerHit } from './types';

/** Searches only customers the server lets this user see; the order is still authorized on submit. */
export function CustomerPicker({
    value,
    onChange,
    error,
}: {
    value: CustomerHit | null;
    onChange: (customer: CustomerHit | null) => void;
    error?: string;
}) {
    const t = useT();
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<CustomerHit[]>([]);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        if (value !== null) {
            return;
        }

        let cancelled = false;
        const timer = setTimeout(async () => {
            const result = await api<{ customers: CustomerHit[] }>(
                'GET',
                OrderController.customers.url({ query: { q } }),
            );
            if (!cancelled) {
                setHits(result.data?.customers ?? []);
                setLoaded(true);
            }
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [q, value]);

    if (value !== null) {
        return (
            <div
                className="flex flex-wrap items-center gap-3 rounded-lg border border-brand bg-brand-subtle px-4 py-3"
                data-test="customer-chosen"
            >
                <div className="min-w-0 flex-1">
                    <p className="font-semibold text-strong">{value.name}</p>
                    <p className="flex flex-wrap gap-x-4 text-caption text-weak">
                        <bdi dir="ltr">{value.mobile}</bdi>
                        {value.branch && <span>{value.branch}</span>}
                        {value.kind === 'organization' && (
                            <Badge variant="info">
                                {t('customers.kind.organization')}
                            </Badge>
                        )}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onChange(null)}
                >
                    {t('orders.create.change_customer')}
                </Button>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Field label={t('orders.create.customer')} error={error} required>
                {(control) => (
                    <input
                        {...control}
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('orders.create.customer_search')}
                        className="field-control w-full text-control"
                        data-test="customer-search"
                    />
                )}
            </Field>
            {loaded && hits.length === 0 ? (
                <p className="text-caption text-weak">
                    {t('orders.create.no_customers')}
                </p>
            ) : (
                <ul
                    className="grid max-h-56 gap-1 overflow-auto"
                    data-test="customer-results"
                >
                    {hits.map((c) => (
                        <li key={c.id}>
                            <button
                                type="button"
                                onClick={() => onChange(c)}
                                className="flex min-h-11 w-full flex-wrap items-center justify-between gap-2 rounded-md px-3 text-start hover:bg-hover"
                            >
                                <span className="font-semibold text-strong">
                                    {c.name}
                                </span>
                                <span className="text-caption text-weak">
                                    <bdi dir="ltr">{c.mobile}</bdi>
                                    {c.branch ? ` · ${c.branch}` : ''}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

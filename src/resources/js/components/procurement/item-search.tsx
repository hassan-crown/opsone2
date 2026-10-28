import { Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import { Icon } from '@/components/opsone/icon';
import { unitLabel } from '@/components/procurement/shared';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { ItemResult } from '@/types/procurement';

/** Search box over `GET purchases/items` (debounced); picking a result adds a line. */
export function ItemSearch({ onPick }: { onPick: (item: ItemResult) => void }) {
    const t = useT();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<ItemResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const counter = useRef(0);

    useEffect(() => {
        if (!open) {
            return;
        }
        const id = ++counter.current;
        const timer = window.setTimeout(async () => {
            setLoading(true);
            const result = await api<{ items: ItemResult[] }>(
                'GET',
                PurchaseController.items.url({ query: { q } }),
            );
            if (id !== counter.current) {
                return;
            }
            setLoading(false);
            setFailed(!result.ok);
            setResults(result.data?.items ?? []);
        }, 300);

        return () => window.clearTimeout(timer);
    }, [q, open]);

    return (
        <div className="grid gap-2">
            <Label htmlFor="item-search">{t('procurement.add_item')}</Label>
            <div className="relative">
                <Icon
                    icon={Search}
                    size="sm"
                    className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-weak"
                />
                <Input
                    id="item-search"
                    type="search"
                    value={q}
                    autoComplete="off"
                    placeholder={t('procurement.item_search_placeholder')}
                    className="ps-10"
                    data-test="item-search"
                    onFocus={() => setOpen(true)}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setOpen(true);
                    }}
                />
            </div>
            {open && (
                <div
                    className="rounded-md border border-stroke-weak bg-surface"
                    aria-live="polite"
                >
                    {loading && results.length === 0 ? (
                        <p className="px-3 py-2 text-body text-weak">
                            {t('procurement.item_searching')}
                        </p>
                    ) : failed ? (
                        <p className="px-3 py-2 text-body text-danger-text">
                            {t('procurement.item_search_failed')}
                        </p>
                    ) : results.length === 0 ? (
                        <p className="px-3 py-2 text-body text-weak">
                            {t('procurement.item_none')}
                        </p>
                    ) : (
                        <ul className="max-h-64 overflow-y-auto">
                            {results.map((item) => (
                                <li
                                    key={`${item.item_type}-${item.item_id}`}
                                    className="border-b border-stroke-weak last:border-0"
                                >
                                    <button
                                        type="button"
                                        data-test="item-result"
                                        className="flex min-h-11 w-full cursor-pointer items-center gap-3 px-3 py-2 text-start hover:bg-hover"
                                        onClick={() => {
                                            onPick(item);
                                            setOpen(false);
                                            setQ('');
                                        }}
                                    >
                                        <Icon icon={Plus} size="sm" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-strong">
                                                {item.name.label}
                                            </span>
                                            {item.code && (
                                                <span className="block text-caption text-weak">
                                                    {item.code}
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-caption text-weak">
                                            {unitLabel(item.unit, t)}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

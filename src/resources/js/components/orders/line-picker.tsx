import { Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { Amount } from '@/components/opsone/amount';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';
import type { CatalogItem, DraftLine, Localized, OrderKind } from './types';

const pick = (text: Localized | undefined, locale: 'ar' | 'en'): string =>
    text?.[locale] || text?.ar || text?.en || '';

/**
 * Catalog line picker (COM-11): search the selectable catalog, choose options and quantity. The version shown is
 * the one the line freezes when the order is saved; later catalog edits never reach it.
 */
export function LinePicker({
    kind,
    branchId,
    onAdd,
}: {
    kind: OrderKind;
    branchId: number | null;
    onAdd: (line: DraftLine) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [mode, setMode] = useState<'catalog' | 'need'>('catalog');
    const [q, setQ] = useState('');
    const [items, setItems] = useState<CatalogItem[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [chosen, setChosen] = useState<CatalogItem | null>(null);
    const [quantity, setQuantity] = useState('1');
    const [unit, setUnit] = useState('');
    const [options, setOptions] = useState<Record<string, string>>({});
    const [description, setDescription] = useState('');
    const counter = useRef(0);

    useEffect(() => {
        if (mode !== 'catalog' || branchId === null) {
            return;
        }

        let cancelled = false;
        const timer = setTimeout(async () => {
            const result = await api<{ items: CatalogItem[] }>(
                'GET',
                OrderController.catalog.url({
                    query: { kind, branch_id: branchId, q },
                }),
            );
            if (!cancelled) {
                setItems(result.data?.items ?? []);
                setLoaded(true);
            }
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [kind, branchId, q, mode]);

    const reset = () => {
        setChosen(null);
        setQuantity('1');
        setUnit('');
        setOptions({});
        setDescription('');
    };

    const add = () => {
        counter.current += 1;
        const key = `${Date.now()}-${counter.current}`;

        if (mode === 'need') {
            onAdd({
                key,
                type: 'custom_need',
                description: description.trim(),
                quantity,
                unit,
                name: description.trim(),
            });
            reset();

            return;
        }
        if (chosen === null) {
            return;
        }

        const chosenOptions = Object.entries(options).filter(([, v]) => v);
        onAdd({
            key,
            type: chosen.type,
            product_id:
                chosen.type === 'custom_product' ? chosen.id : undefined,
            variant_id: chosen.type === 'ready_variant' ? chosen.id : undefined,
            quantity,
            unit: chosen.type === 'custom_product' ? unit : undefined,
            options: chosenOptions.map(([k, value]) => ({ key: k, value })),
            optionsLabel: chosenOptions.map(([, v]) => v).join('، '),
            name: chosen.name,
            version: chosen.version,
            price_minor:
                chosen.price_minor ?? chosen.reference_price_minor ?? undefined,
        });
        reset();
    };

    const canAdd =
        mode === 'need'
            ? description.trim() !== '' && Number(quantity) > 0
            : chosen !== null && Number(quantity) > 0;

    return (
        <div
            className="grid gap-3 rounded-lg border border-stroke-weak p-3"
            data-test="line-picker"
        >
            {kind === 'custom' && (
                <div
                    className="flex gap-2"
                    role="group"
                    aria-label={t('orders.picker.mode')}
                >
                    {(['catalog', 'need'] as const).map((m) => (
                        <Button
                            key={m}
                            type="button"
                            size="sm"
                            variant={mode === m ? 'default' : 'secondary'}
                            aria-pressed={mode === m}
                            onClick={() => {
                                setMode(m);
                                reset();
                            }}
                        >
                            {t(
                                m === 'catalog'
                                    ? 'orders.picker.catalog'
                                    : 'orders.picker.need',
                            )}
                        </Button>
                    ))}
                </div>
            )}

            {mode === 'need' ? (
                <TextField
                    label={t('orders.picker.need_description')}
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    maxLength={2000}
                />
            ) : chosen === null ? (
                <>
                    <div className="relative">
                        <Icon
                            icon={Search}
                            className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-weak"
                        />
                        <input
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('orders.picker.search')}
                            aria-label={t('orders.picker.search')}
                            className="field-control w-full ps-10 text-control"
                        />
                    </div>
                    {branchId === null ? (
                        <p className="text-caption text-weak">
                            {t('orders.picker.need_branch')}
                        </p>
                    ) : loaded && items.length === 0 ? (
                        <p className="text-caption text-weak">
                            {t('orders.picker.none')}
                        </p>
                    ) : (
                        <ul
                            className="grid max-h-60 gap-1 overflow-auto"
                            data-test="catalog-results"
                        >
                            {items.map((item) => (
                                <li key={`${item.type}-${item.id}`}>
                                    <button
                                        type="button"
                                        onClick={() => setChosen(item)}
                                        className="flex min-h-11 w-full items-center justify-between gap-2 rounded-md px-3 text-start hover:bg-hover"
                                    >
                                        <span className="min-w-0">
                                            <span className="font-semibold text-strong">
                                                {item.name}
                                            </span>
                                            {item.code && (
                                                <bdi
                                                    dir="ltr"
                                                    className="ms-2 text-caption text-weak"
                                                >
                                                    {item.code}
                                                </bdi>
                                            )}
                                        </span>
                                        <span className="flex shrink-0 items-center gap-2">
                                            {item.type === 'custom_product' &&
                                                item.reference_price_minor !=
                                                    null && (
                                                    <span className="text-caption text-weak">
                                                        {t(
                                                            'orders.reference_price',
                                                        )}
                                                    </span>
                                                )}
                                            {(item.price_minor ??
                                                item.reference_price_minor) !=
                                                null && (
                                                <Amount
                                                    value={
                                                        (item.price_minor ??
                                                            item.reference_price_minor ??
                                                            0) / 100
                                                    }
                                                />
                                            )}
                                            <Badge variant="neutral">
                                                {t('orders.version', {
                                                    n: item.version,
                                                })}
                                            </Badge>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            ) : (
                <div className="grid gap-3">
                    <p className="flex flex-wrap items-center gap-2 font-semibold text-strong">
                        {chosen.name}
                        <Badge variant="neutral">
                            {t('orders.version', { n: chosen.version })}
                        </Badge>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={reset}
                        >
                            {t('orders.picker.change')}
                        </Button>
                    </p>
                    <p className="text-caption text-weak">
                        {t('orders.picker.frozen_hint')}
                    </p>
                    {chosen.option_groups?.map((group) => (
                        <SelectField
                            key={group.key}
                            label={pick(group.label, locale)}
                            value={options[group.key] ?? ''}
                            onChange={(e) =>
                                setOptions({
                                    ...options,
                                    [group.key]: e.target.value,
                                })
                            }
                            options={[
                                {
                                    value: '',
                                    label: t('orders.picker.no_option'),
                                },
                                ...(group.values ?? []).map((v) => ({
                                    value: pick(v, locale),
                                    label: pick(v, locale),
                                })),
                            ]}
                            optional
                        />
                    ))}
                </div>
            )}

            {(mode === 'need' || chosen !== null) && (
                <div className="grid grid-cols-2 gap-3">
                    <TextField
                        label={t('orders.picker.quantity')}
                        type="number"
                        inputMode="decimal"
                        min={kind === 'ready' ? 1 : 0.001}
                        step={kind === 'ready' ? 1 : 'any'}
                        dir="ltr"
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value)}
                    />
                    {kind === 'custom' && (
                        <TextField
                            label={t('orders.picker.unit')}
                            value={unit}
                            maxLength={16}
                            onChange={(e) => setUnit(e.target.value)}
                            optional
                        />
                    )}
                </div>
            )}

            {(mode === 'need' || chosen !== null) && (
                <div>
                    <Button
                        type="button"
                        onClick={add}
                        disabled={!canAdd}
                        data-test="add-line"
                    >
                        <Icon icon={Plus} />
                        {t('orders.picker.add')}
                    </Button>
                </div>
            )}
        </div>
    );
}

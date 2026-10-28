import { Plus, Trash2 } from 'lucide-react';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { Trackable } from './types';

export type ItemLine = { key: string; item: string; quantity: string };

export const itemKey = (i: { type: string; item_id: number }): string =>
    `${i.type}:${i.item_id}`;

export function splitKey(key: string): { item_type: string; item_id: number } {
    const [type, id] = key.split(':');

    return { item_type: type, item_id: Number(id) };
}

/**
 * Editable list of (item, quantity) lines for transfers and counts. The server re-validates every line (tracked item,
 * fixed unit, quantity with up to three decimals).
 */
export function ItemLines({
    items,
    lines,
    onChange,
    quantityLabel,
    fieldError,
}: {
    items: Trackable[];
    lines: ItemLine[];
    onChange: (lines: ItemLine[]) => void;
    quantityLabel: string;
    fieldError: (field: string) => string | undefined;
}) {
    const t = useT();
    const unitOf = (key: string) =>
        items.find((i) => itemKey(i) === key)?.unit ?? null;
    const update = (index: number, patch: Partial<ItemLine>) =>
        onChange(lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    return (
        <div className="grid gap-3" data-test="item-lines">
            {lines.map((line, index) => {
                const unit = unitOf(line.item);

                return (
                    <div
                        key={line.key}
                        className="grid items-end gap-2 sm:grid-cols-[1fr_10rem_auto]"
                    >
                        <SelectField
                            label={t('stock.field.item')}
                            value={line.item}
                            required
                            placeholder={t('stock.choose_item')}
                            options={items.map((i) => ({
                                value: itemKey(i),
                                label: i.code
                                    ? `${i.name} (${i.code})`
                                    : i.name,
                            }))}
                            error={fieldError(`lines.${index}.item_id`)}
                            onChange={(e) =>
                                update(index, { item: e.target.value })
                            }
                        />
                        <TextField
                            label={
                                unit
                                    ? `${quantityLabel} (${t(`stock.unit.${unit}` as TranslationKey)})`
                                    : quantityLabel
                            }
                            dir="ltr"
                            inputMode="decimal"
                            required
                            value={line.quantity}
                            error={
                                fieldError(`lines.${index}.quantity`) ??
                                fieldError(`lines.${index}.counted`)
                            }
                            onChange={(e) =>
                                update(index, { quantity: e.target.value })
                            }
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={t('stock.remove_line')}
                            disabled={lines.length === 1}
                            onClick={() =>
                                onChange(lines.filter((_, i) => i !== index))
                            }
                        >
                            <Icon icon={Trash2} />
                        </Button>
                    </div>
                );
            })}
            <div>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={() =>
                        onChange([
                            ...lines,
                            {
                                key: String(Date.now() + lines.length),
                                item: '',
                                quantity: '',
                            },
                        ])
                    }
                >
                    <Icon icon={Plus} />
                    {t('stock.add_line')}
                </Button>
            </div>
        </div>
    );
}

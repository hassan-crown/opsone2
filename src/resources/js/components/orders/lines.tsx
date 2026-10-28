import { Trash2 } from 'lucide-react';
import { Amount } from '@/components/opsone/amount';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLocale, useT } from '@/lib/i18n';
import type { DraftLine, Localized, OrderLine } from './types';

export const localized = (
    text: Localized | string | null | undefined,
    locale: 'ar' | 'en',
): string =>
    typeof text === 'string'
        ? text
        : (text?.[locale] ?? text?.ar ?? text?.en ?? '');

/** Name of a saved line from its frozen snapshot (never from the live catalog). */
export function lineName(line: OrderLine, locale: 'ar' | 'en'): string {
    if (line.type === 'custom_need') {
        return line.description ?? '';
    }
    const item = line.snapshot.item;
    const name = localized(item?.name, locale);

    return line.type === 'ready_variant'
        ? `${localized(item?.product_name, locale)} — ${name}`
        : name;
}

export function optionsText(line: OrderLine, locale: 'ar' | 'en'): string {
    return line.snapshot.options
        .map((o) => `${localized(o.label, locale) || o.key}: ${o.value}`)
        .join('، ');
}

/** Lines the user is composing in the create form. */
export function DraftLines({
    lines,
    onRemove,
}: {
    lines: DraftLine[];
    onRemove: (key: string) => void;
}) {
    const t = useT();

    if (lines.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-2" data-test="draft-lines">
            {lines.map((line) => (
                <li
                    key={line.key}
                    className="flex items-start justify-between gap-3 rounded-lg bg-surface px-3 py-2 ring-1 ring-stroke-weak"
                >
                    <div className="min-w-0">
                        <p className="font-semibold text-strong">
                            {line.name}{' '}
                            {line.version !== undefined && (
                                <Badge variant="neutral">
                                    {t('orders.version', { n: line.version })}
                                </Badge>
                            )}
                        </p>
                        <p className="text-caption text-weak">
                            <bdi dir="ltr">{line.quantity}</bdi>
                            {line.unit ? ` ${line.unit}` : ''}
                            {line.optionsLabel ? ` · ${line.optionsLabel}` : ''}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {line.price_minor !== undefined && (
                            <span className="flex items-center gap-1">
                                {line.type === 'custom_product' && (
                                    <span className="text-caption text-weak">
                                        {t('orders.reference_price')}
                                    </span>
                                )}
                                <Amount value={line.price_minor / 100} />
                            </span>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={t('orders.lines.remove')}
                            onClick={() => onRemove(line.key)}
                        >
                            <Icon icon={Trash2} />
                        </Button>
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function useLocaleName() {
    const { locale } = useLocale();

    return (line: OrderLine) => lineName(line, locale);
}

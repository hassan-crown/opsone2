import { router } from '@inertiajs/react';
import { Store } from 'lucide-react';
import { useState } from 'react';
import OrderSalesChannelController from '@/actions/App/Http/Controllers/FieldSales/OrderSalesChannelController';
import { SelectField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type OrderSalesChannel = {
    channel: string;
    seller: string | null;
    seller_id: number | null;
    lock_version: number;
    channels: string[];
    can_change: boolean;
    reps: { id: number; name: string }[];
};

/**
 * «قناة البيع» (ADR-0041): showroom, online store or field sales, and the field rep who sold the order. Correcting it
 * needs P-106 and is audited; a commission already pinned keeps its owner (that is a sale correction).
 */
export function SalesChannelCard({
    orderId,
    sales,
}: {
    orderId: number;
    sales: OrderSalesChannel;
}) {
    const t = useT();
    const [editing, setEditing] = useState(false);
    const [channel, setChannel] = useState(sales.channel);
    const [seller, setSeller] = useState(
        String(sales.seller_id ?? sales.reps[0]?.id ?? ''),
    );
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const label = (c: string) =>
        t(`field_sales.channel.${c}` as TranslationKey);

    return (
        <Card data-test="sales-channel-card">
            <div className="flex flex-wrap items-center gap-2">
                <Icon icon={Store} className="text-weak" />
                <h2 className="text-heading m-0 text-strong">
                    {t('field_sales.channel.title')}
                </h2>
                <Badge variant="info" className="ms-auto">
                    {label(sales.channel)}
                </Badge>
            </div>
            {sales.seller && (
                <p className="m-0 text-body">
                    {t('field_sales.channel.seller', { name: sales.seller })}
                </p>
            )}
            {sales.can_change && !editing && (
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    className="self-start"
                    onClick={() => setEditing(true)}
                >
                    {t('field_sales.channel.change')}
                </Button>
            )}
            {editing && (
                <form
                    className="grid gap-3 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setBusy(true);
                        router.post(
                            OrderSalesChannelController.url(orderId),
                            {
                                lock_version: sales.lock_version,
                                sales_channel: channel,
                                seller_id:
                                    channel === 'field_sales' && seller
                                        ? Number(seller)
                                        : null,
                            },
                            {
                                preserveScroll: true,
                                onError: (e) =>
                                    setErrors(e as Record<string, string>),
                                onSuccess: () => {
                                    setErrors({});
                                    setEditing(false);
                                },
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    <SelectField
                        label={t('field_sales.channel.title')}
                        value={channel}
                        error={errors.sales_channel}
                        onChange={(e) => setChannel(e.target.value)}
                        options={sales.channels.map((c) => ({
                            value: c,
                            label: label(c),
                        }))}
                    />
                    {channel === 'field_sales' && (
                        <SelectField
                            label={t('field_sales.channel.seller_label')}
                            value={seller}
                            error={errors.seller_id}
                            onChange={(e) => setSeller(e.target.value)}
                            options={sales.reps.map((r) => ({
                                value: String(r.id),
                                label: r.name,
                            }))}
                        />
                    )}
                    <p className="m-0 text-caption text-weak sm:col-span-2">
                        {t('field_sales.channel.change_hint')}
                    </p>
                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" size="sm" loading={busy}>
                            {t('field_sales.channel.save')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setEditing(false)}
                        >
                            {t('field_sales.cancel')}
                        </Button>
                    </div>
                </form>
            )}
        </Card>
    );
}

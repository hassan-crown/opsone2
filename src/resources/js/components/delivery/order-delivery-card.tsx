import { Link } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import DeliveryController from '@/actions/App/Http/Controllers/Delivery/DeliveryController';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type OrderDelivery = {
    can_record: boolean;
    complete: boolean;
    notes: {
        id: number;
        number: string | null;
        status: 'draft' | 'confirmed';
        delivered_on: string | null;
    }[];
};

/** Delivery side of an order (DelList entry): record a delivery and open the notes so far. */
export function OrderDeliveryCard({
    orderId,
    delivery,
}: {
    orderId: number;
    delivery: OrderDelivery;
}) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <Card data-test="order-delivery">
            <h2 className="text-heading mb-2 flex items-center gap-2 text-strong">
                <Icon icon={PackageCheck} />
                {t('delivery.order.title')}
            </h2>
            <p className="mb-3 text-caption text-weak">
                {delivery.complete
                    ? t('delivery.order.complete')
                    : t('delivery.order.hint')}
            </p>
            {delivery.notes.length > 0 && (
                <ul className="mb-3 grid gap-1">
                    {delivery.notes.map((n) => (
                        <li key={n.id}>
                            <Link
                                href={DeliveryController.show.url(n.id)}
                                className="text-link hover:underline"
                            >
                                <bdi dir="ltr">
                                    {n.number ?? t('delivery.draft_no')}
                                </bdi>
                            </Link>{' '}
                            <span className="text-caption text-weak">
                                {t(
                                    `delivery.status.${n.status}` as TranslationKey,
                                )}
                                {n.delivered_on
                                    ? ` · ${formatDate(n.delivered_on, locale)}`
                                    : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {delivery.can_record && (
                <Button
                    asChild
                    variant="secondary"
                    size="sm"
                    data-test="record-delivery-link"
                >
                    <Link href={DeliveryController.create.url(orderId)}>
                        {t('delivery.deliver')}
                    </Link>
                </Button>
            )}
        </Card>
    );
}

import { Link } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import CancellationController from '@/actions/App/Http/Controllers/Cancellation/CancellationController';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

export type OrderCancellation = {
    can_review: boolean;
    pending: { id: number; number: string } | null;
    settlement: { status: 'open' | 'closed'; open: number } | null;
};

/** Cancellation entry point of an order (CancelReview) and the state of its settlement follow-up. */
export function OrderCancelCard({
    orderId,
    cancellation,
}: {
    orderId: number;
    cancellation: OrderCancellation;
}) {
    const t = useT();
    const href = CancellationController.show.url(orderId);

    return (
        <Card data-test="order-cancellation">
            <h2 className="text-heading mb-2 flex items-center gap-2 text-strong">
                <Icon icon={Ban} />
                {t('cancel.order.title')}
            </h2>
            {cancellation.pending && (
                <p className="mb-2 flex items-center gap-2">
                    <Badge variant="warning">
                        {t('cancel.status.pending')}
                    </Badge>
                    <bdi dir="ltr">{cancellation.pending.number}</bdi>
                </p>
            )}
            {cancellation.settlement && (
                <p className="mb-3 text-caption text-weak">
                    {cancellation.settlement.status === 'open'
                        ? t('cancel.order.settlement_open', {
                              n: cancellation.settlement.open,
                          })
                        : t('cancel.order.settlement_closed')}
                </p>
            )}
            {!cancellation.pending && cancellation.can_review && (
                <p className="mb-3 text-caption text-weak">
                    {t('cancel.order.hint')}
                </p>
            )}
            <Button asChild variant="secondary" className="w-full">
                <Link href={href} data-test="open-cancel-review">
                    {cancellation.can_review
                        ? t('cancel.order.review')
                        : t('cancel.order.follow_up')}
                </Link>
            </Button>
        </Card>
    );
}

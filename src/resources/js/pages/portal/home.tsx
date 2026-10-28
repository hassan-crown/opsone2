import { Head, Link } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    OrderRow,
    RequiredItem,
    Section,
    UpdateItem,
} from '@/components/portal/parts';
import { usePortal } from '@/layouts/portal-layout';
import { useT } from '@/lib/i18n';
import { notifications } from '@/routes/customer';
import type { PortalOrder, PortalRequired, PortalUpdate } from '@/types/portal';

type Props = {
    required: PortalRequired[];
    orders: PortalOrder[];
    updates: PortalUpdate[];
};

/** CustHome (WEB-09): «مطلوب منك» first, then open orders and the latest updates. */
export default function PortalHome({ required, orders, updates }: Props) {
    const t = useT();
    const { customer } = usePortal();

    return (
        <>
            <Head title={t('portal.nav.overview')} />
            <h1 className="text-title text-strong">
                {t('portal.home.hello', { name: customer?.name ?? '' })}
            </h1>
            <p className="mt-1 mb-6 text-body text-weak">
                {t('portal.home.summary', {
                    orders: orders.length,
                    required: required.length,
                })}
            </p>

            <Section title={t('portal.home.required')}>
                {required.length === 0 ? (
                    <EmptyState
                        compact
                        icon={CircleCheck}
                        title={t('portal.home.nothing_required')}
                        description={t('portal.home.nothing_required_hint')}
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {required.map((item) => (
                            <RequiredItem
                                key={`${item.type}-${item.id}`}
                                item={item}
                            />
                        ))}
                    </ul>
                )}
            </Section>

            {orders.length > 0 && (
                <Section title={t('portal.home.open_orders')}>
                    <div className="flex flex-col gap-3">
                        {orders.map((order) => (
                            <OrderRow key={order.id} order={order} />
                        ))}
                    </div>
                </Section>
            )}

            {updates.length > 0 && (
                <Section
                    title={t('portal.home.updates')}
                    action={
                        <Link
                            href={notifications().url}
                            className="text-label text-link underline-offset-4 hover:underline"
                        >
                            {t('portal.home.all_updates')}
                        </Link>
                    }
                >
                    <ul className="divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-card px-2">
                        {updates.map((update, i) => (
                            <UpdateItem key={i} update={update} />
                        ))}
                    </ul>
                </Section>
            )}
        </>
    );
}

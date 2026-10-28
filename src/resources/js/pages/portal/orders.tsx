import { Head } from '@inertiajs/react';
import { Package } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/opsone/empty-state';
import { OrderRow } from '@/components/portal/parts';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useT } from '@/lib/i18n';
import type { PortalOrder } from '@/types/portal';

/** CustOrders: the customer's own orders, open and finished. */
export default function PortalOrders({ orders }: { orders: PortalOrder[] }) {
    const t = useT();
    const open = orders.filter((order) => order.open);
    const done = orders.filter((order) => !order.open);
    const [tab, setTab] = useState(
        open.length > 0 || done.length === 0 ? 'open' : 'done',
    );
    const list = tab === 'open' ? open : done;

    return (
        <>
            <Head title={t('portal.nav.orders')} />
            <h1 className="mb-4 text-title text-strong">
                {t('portal.nav.orders')}
            </h1>
            <Tabs value={tab} onValueChange={setTab} className="mb-4">
                <TabsList>
                    <TabsTrigger value="open">
                        {t('portal.orders.open', { count: open.length })}
                    </TabsTrigger>
                    <TabsTrigger value="done">
                        {t('portal.orders.done', { count: done.length })}
                    </TabsTrigger>
                </TabsList>
            </Tabs>
            {list.length === 0 ? (
                <EmptyState icon={Package} title={t('portal.orders.empty')} />
            ) : (
                <div className="flex flex-col gap-3">
                    {list.map((order) => (
                        <OrderRow key={order.id} order={order} />
                    ))}
                </div>
            )}
        </>
    );
}

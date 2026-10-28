import { Head } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { UpdateItem } from '@/components/portal/parts';
import { useT } from '@/lib/i18n';
import type { PortalUpdate } from '@/types/portal';

/** The customer's updates: documents sent and decided, receipts, proof reviews, appointments and cancellation. */
export default function PortalNotifications({
    updates,
}: {
    updates: PortalUpdate[];
}) {
    const t = useT();

    return (
        <>
            <Head title={t('portal.nav.updates')} />
            <h1 className="mb-6 text-title text-strong">
                {t('portal.nav.updates')}
            </h1>
            {updates.length === 0 ? (
                <EmptyState icon={Bell} title={t('portal.updates.empty')} />
            ) : (
                <ul className="divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-card px-2">
                    {updates.map((update, i) => (
                        <UpdateItem key={i} update={update} />
                    ))}
                </ul>
            )}
        </>
    );
}

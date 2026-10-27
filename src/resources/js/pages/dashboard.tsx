import { Head, usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const t = useT();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('dashboard.title')} />
            <PageHeader
                title={t('dashboard.title')}
                description={t('dashboard.welcome', { name: auth.user.name })}
            />
            <Card>
                <EmptyState
                    icon={LayoutGrid}
                    title={t('dashboard.title')}
                    description={t('dashboard.description')}
                />
            </Card>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'dashboard.title',
            href: dashboard(),
        },
    ],
};

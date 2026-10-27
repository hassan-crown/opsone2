import { Head, usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { SetupChecklist } from '@/components/org/setup-checklist';
import type { SetupSummary } from '@/components/org/setup-checklist';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';

export default function Dashboard({ setup }: { setup: SetupSummary | null }) {
    const t = useT();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('dashboard.title')} />
            <PageHeader
                title={t('dashboard.title')}
                description={t('dashboard.welcome', { name: auth.user.name })}
            />
            <div className="flex flex-col gap-4">
                {setup && <SetupChecklist setup={setup} />}
                <Card>
                    <EmptyState
                        icon={LayoutGrid}
                        title={t('dashboard.title')}
                        description={t('dashboard.description')}
                    />
                </Card>
            </div>
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

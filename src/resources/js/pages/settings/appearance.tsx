import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useT } from '@/lib/i18n';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const t = useT();

    return (
        <>
            <Head title={t('appearance.settings_title')} />

            <h1 className="sr-only">{t('appearance.settings_title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('appearance.settings_title')}
                    description={t('appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'appearance.settings_title',
            href: editAppearance(),
        },
    ],
};

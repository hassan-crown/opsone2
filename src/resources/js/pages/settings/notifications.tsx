import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import NotificationPreferenceController from '@/actions/App/Http/Controllers/Notifications/NotificationPreferenceController';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { preferences } from '@/routes/notifications';
import { home } from '@/routes/settings';

type Optional = { type: string; title: string; email: boolean };

export default function NotificationPreferences({
    types,
    mandatory,
}: {
    types: Optional[];
    mandatory: { type: string; title: string }[];
}) {
    const t = useT();
    const [values, setValues] = useState<Record<string, boolean>>(
        Object.fromEntries(types.map((row) => [row.type, row.email])),
    );
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.put(
            NotificationPreferenceController.update.url(),
            {
                preferences: types.map((row) => ({
                    type: row.type,
                    email: values[row.type],
                })),
            },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <>
            <Head title={t('notifications.preferences.title')} />

            <PageHeader
                title={t('notifications.preferences.title')}
                description={t('notifications.preferences.description')}
            />

            <div className="max-w-2xl space-y-8">
                <section className="space-y-3">
                    <h2 className="text-subtitle text-strong">
                        {t('notifications.preferences.optional')}
                    </h2>
                    {types.length === 0 ? (
                        <p className="text-body text-weak">
                            {t('notifications.preferences.none')}
                        </p>
                    ) : (
                        <ul className="m-0 list-none divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-surface p-0">
                            {types.map((row) => (
                                <li
                                    key={row.type}
                                    className="flex min-h-14 items-center justify-between gap-4 px-4 py-2"
                                >
                                    <label
                                        htmlFor={`pref-${row.type}`}
                                        className="text-body text-strong"
                                    >
                                        {t(row.title as TranslationKey)}
                                    </label>
                                    <Switch
                                        id={`pref-${row.type}`}
                                        checked={values[row.type]}
                                        onCheckedChange={(checked) =>
                                            setValues((current) => ({
                                                ...current,
                                                [row.type]: checked,
                                            }))
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    {types.length > 0 && (
                        <Button type="button" onClick={save} loading={saving}>
                            {saving && <Spinner />}
                            {t('notifications.preferences.save')}
                        </Button>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-subtitle text-strong">
                        {t('notifications.preferences.mandatory')}
                    </h2>
                    <p className="text-body text-weak">
                        {t('notifications.preferences.mandatory_note')}
                    </p>
                    <ul className="m-0 list-disc ps-5 text-body text-strong">
                        {mandatory.map((row) => (
                            <li key={row.type}>
                                {t(row.title as TranslationKey)}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

NotificationPreferences.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'notifications.preferences.title', href: preferences() },
    ],
};

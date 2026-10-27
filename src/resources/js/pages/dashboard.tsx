import { Head, usePage } from '@inertiajs/react';
import { BellRing, ListChecks } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { SetupChecklist } from '@/components/org/setup-checklist';
import type { SetupSummary } from '@/components/org/setup-checklist';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { dashboard } from '@/routes';

type Widgets = { tasks: string[]; alerts: string[] };

/**
 * Work-center frame (SHR-01). Each section lists the widgets this user's permissions allow; domains fill a
 * widget with data later, until then it shows a placeholder.
 */
function Section({
    id,
    title,
    description,
    icon,
    widgets,
}: {
    id: 'tasks' | 'alerts';
    title: string;
    description: string;
    icon: typeof ListChecks;
    widgets: string[];
}) {
    const t = useT();

    return (
        <section
            aria-labelledby={`home-${id}`}
            className="flex flex-col gap-3"
            data-test={`home-${id}`}
        >
            <div>
                <h2 id={`home-${id}`} className="text-subtitle text-strong">
                    {title}
                </h2>
                <p className="text-body text-weak">{description}</p>
            </div>
            {widgets.length === 0 ? (
                <Card>
                    <EmptyState
                        compact
                        icon={icon}
                        title={t('home.section_empty')}
                    />
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {widgets.map((key) => (
                        <Card
                            key={key}
                            className="p-5"
                            data-test={`home-widget-${key}`}
                        >
                            <h3 className="text-control font-semibold text-strong">
                                {t(
                                    `home.widget.${key}.title` as TranslationKey,
                                )}
                            </h3>
                            <p className="mt-1 text-body text-weak">
                                {t(
                                    `home.widget.${key}.description` as TranslationKey,
                                )}
                            </p>
                            <p className="mt-4 rounded-md bg-fill px-3 py-2 text-caption text-weak">
                                {t('home.widget.placeholder')}
                            </p>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

export default function Dashboard({
    setup,
    widgets,
}: {
    setup: SetupSummary | null;
    widgets: Widgets;
}) {
    const t = useT();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('dashboard.title')} />
            <PageHeader
                title={t('dashboard.title')}
                description={t('dashboard.welcome', { name: auth.user.name })}
            />
            <div className="flex flex-col gap-8">
                {setup && <SetupChecklist setup={setup} />}
                <Section
                    id="tasks"
                    title={t('home.tasks.title')}
                    description={t('home.tasks.description')}
                    icon={ListChecks}
                    widgets={widgets.tasks}
                />
                <Section
                    id="alerts"
                    title={t('home.alerts.title')}
                    description={t('home.alerts.description')}
                    icon={BellRing}
                    widgets={widgets.alerts}
                />
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

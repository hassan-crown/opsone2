import { Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';

type Entry = {
    version: string;
    released_on: string;
    title: string;
    body: string;
};

export default function WhatsNew({ entries }: { entries: Entry[] }) {
    const t = useT();
    const { locale } = useLocale();
    const date = (value: string) =>
        new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(
            new Date(value),
        );

    return (
        <>
            <Head title={t('help.whats_new.title')} />
            <PageHeader
                title={t('help.whats_new.title')}
                description={t('help.whats_new.description')}
            />
            {entries.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Sparkles}
                        title={t('help.whats_new.empty')}
                    />
                </Card>
            ) : (
                <div className="flex max-w-reading flex-col gap-4">
                    {entries.map((entry) => (
                        <Card key={entry.version} className="p-6">
                            <p className="text-caption text-weak">
                                {t('help.whats_new.version', {
                                    version: entry.version,
                                })}{' '}
                                · {date(entry.released_on)}
                            </p>
                            <h2 className="mt-1 text-subtitle text-strong">
                                {entry.title}
                            </h2>
                            <p className="mt-2 text-body text-strong">
                                {entry.body}
                            </p>
                        </Card>
                    ))}
                </div>
            )}
        </>
    );
}

WhatsNew.layout = {
    breadcrumbs: [
        { title: 'help.title', href: HelpController.index() },
        { title: 'help.whats_new.title', href: HelpController.whatsNew() },
    ],
};

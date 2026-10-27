import { Head, Link } from '@inertiajs/react';
import { SearchX } from 'lucide-react';
import { useState } from 'react';
import SearchController from '@/actions/App/Http/Controllers/SearchController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type SearchGroup = {
    key: string;
    results: {
        type: string;
        title: string;
        url: string;
        subtitle: string | null;
    }[];
};

export default function SearchIndex({
    q,
    groups,
}: {
    q: string;
    groups: SearchGroup[];
}) {
    const t = useT();
    const [value, setValue] = useState(q);

    return (
        <>
            <Head title={t('search.title')} />
            <PageHeader
                title={t('search.title')}
                description={q ? t('search.results_for', { q }) : undefined}
            />
            <form
                method="get"
                action={SearchController.index.url()}
                role="search"
                className="mb-6 max-w-xl"
            >
                <Input
                    type="search"
                    name="q"
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    maxLength={100}
                    aria-label={t('search.label')}
                    placeholder={t('search.placeholder')}
                    data-test="search-page-input"
                />
            </form>

            {q.length >= 2 && groups.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={SearchX}
                        title={t('search.no_results')}
                        description={t('search.no_results_hint')}
                    />
                </Card>
            ) : (
                <div className="flex max-w-reading flex-col gap-6">
                    {groups.map((group) => (
                        <section
                            key={group.key}
                            className="flex flex-col gap-2"
                        >
                            <h2 className="text-subtitle text-strong">
                                {t(
                                    `search.group.${group.key}` as TranslationKey,
                                )}
                            </h2>
                            <Card className="divide-y divide-stroke-weak p-0">
                                {group.results.map((result) => (
                                    <Link
                                        key={result.url + result.title}
                                        href={result.url}
                                        className="flex flex-col gap-0.5 px-5 py-3 text-strong no-underline hover:bg-hover"
                                    >
                                        <span className="font-semibold">
                                            {result.title}
                                        </span>
                                        {result.subtitle && (
                                            <span className="text-caption text-weak">
                                                {result.subtitle}
                                            </span>
                                        )}
                                    </Link>
                                ))}
                            </Card>
                        </section>
                    ))}
                </div>
            )}
        </>
    );
}

SearchIndex.layout = {
    breadcrumbs: [{ title: 'search.title', href: SearchController.index() }],
};

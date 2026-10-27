import { Head, Link } from '@inertiajs/react';
import { ChevronRight, LifeBuoy, Sparkles } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { ArticleCard } from '@/components/help/article-card';
import type { ArticleSummary } from '@/components/help/article-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { support } from '@/routes/help';

type Props = {
    categories: { key: string; articles: ArticleSummary[] }[];
    latest: { version: string; released_on: string; title: string } | null;
};

export default function HelpIndex({ categories, latest }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('help.title')} />
            <PageHeader
                title={t('help.title')}
                description={t('help.description')}
                actions={
                    <Button variant="secondary" asChild>
                        <Link href={support()}>
                            <Icon icon={LifeBuoy} />
                            {t('help.support.title')}
                        </Link>
                    </Button>
                }
            />

            {latest && (
                <Link
                    href={HelpController.whatsNew()}
                    className="mb-6 flex items-center justify-between gap-4 rounded-xl bg-brand-subtle px-5 py-4 text-brand-subtle-text no-underline"
                    data-test="help-whats-new"
                >
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="flex items-center gap-2 font-semibold">
                            <Icon icon={Sparkles} size="sm" />
                            {t('help.whats_new.banner', {
                                version: latest.version,
                            })}
                        </span>
                        <span>{latest.title}</span>
                    </span>
                    <Icon icon={ChevronRight} className="rtl:rotate-180" />
                </Link>
            )}

            {categories.length === 0 ? (
                <Card>
                    <EmptyState icon={LifeBuoy} title={t('help.empty')} />
                </Card>
            ) : (
                <div className="flex flex-col gap-8">
                    {categories.map((category) => (
                        <section
                            key={category.key}
                            className="flex flex-col gap-3"
                        >
                            <h2 className="text-subtitle text-strong">
                                {t(
                                    `help.category.${category.key}` as TranslationKey,
                                )}
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                {category.articles.map((article) => (
                                    <ArticleCard
                                        key={article.slug}
                                        article={article}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </>
    );
}

HelpIndex.layout = {
    breadcrumbs: [{ title: 'help.title', href: HelpController.index() }],
};

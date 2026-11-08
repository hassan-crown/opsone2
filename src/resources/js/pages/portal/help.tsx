import { Head } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { ArticleCard } from '@/components/help/article-card';
import type { ArticleSummary } from '@/components/help/article-card';
import { EmptyState } from '@/components/opsone/empty-state';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { article as articleRoute } from '@/routes/customer/help';

type Props = { categories: { key: string; articles: ArticleSummary[] }[] };

/** Customer help (ADR-0048): customer articles only, filtered by the server. */
export default function PortalHelp({ categories }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('help.title')} />
            <div className="mb-6">
                <h1 className="text-title text-strong">{t('help.title')}</h1>
                <p className="mt-1 text-body text-weak">
                    {t('portal.help.description')}
                </p>
            </div>

            {categories.length === 0 ? (
                <EmptyState icon={CircleHelp} title={t('help.empty')} />
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
                            <div className="grid gap-4 sm:grid-cols-2">
                                {category.articles.map((article) => (
                                    <ArticleCard
                                        key={article.slug}
                                        article={article}
                                        href={articleRoute(article.slug).url}
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

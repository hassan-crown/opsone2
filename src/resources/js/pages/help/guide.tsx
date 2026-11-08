import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BookUser } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { ArticleBody } from '@/components/help/article-body';
import { ArticleCard } from '@/components/help/article-card';
import type { ArticleSummary } from '@/components/help/article-card';
import { LockedBadge } from '@/components/help/locked-badge';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Guide = ArticleSummary & { body_html: string };

type Props = {
    roles: string[];
    guides: Guide[];
    articles: (ArticleSummary & { category: string })[];
};

/** «My guide»: the walkthrough of the user's roles, then the articles written for them (filtered on the server). */
export default function HelpGuide({ roles, guides, articles }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('help.guide.title')} />
            <PageHeader
                title={t('help.guide.title')}
                description={
                    roles.length > 0
                        ? t('help.guide.description', {
                              roles: roles.join('، '),
                          })
                        : undefined
                }
                back={
                    <Link
                        href={HelpController.index()}
                        className="inline-flex items-center gap-1 text-control"
                    >
                        <Icon icon={ArrowLeft} size="sm" />
                        {t('help.back')}
                    </Link>
                }
            />

            {guides.length === 0 && articles.length === 0 ? (
                <Card>
                    <EmptyState icon={BookUser} title={t('help.guide.empty')} />
                </Card>
            ) : (
                <div className="flex max-w-reading flex-col gap-8">
                    {guides.map((guide) => (
                        <Card
                            key={guide.slug}
                            className="p-6"
                            data-test="help-guide"
                        >
                            <h2 className="mb-1 flex flex-wrap items-center gap-2 text-subtitle text-strong">
                                {guide.title}
                                {guide.locked && <LockedBadge />}
                            </h2>
                            {guide.summary && (
                                <p className="mb-4 text-body text-weak">
                                    {guide.summary}
                                </p>
                            )}
                            <ArticleBody html={guide.body_html} />
                        </Card>
                    ))}

                    {articles.length > 0 && (
                        <section className="flex flex-col gap-3">
                            <h2 className="text-subtitle text-strong">
                                {t('help.guide.articles')}
                            </h2>
                            {articles.map((article) => (
                                <ArticleCard
                                    key={article.slug}
                                    article={article}
                                />
                            ))}
                        </section>
                    )}
                </div>
            )}
        </>
    );
}

HelpGuide.layout = {
    breadcrumbs: [
        { title: 'help.title', href: HelpController.index() },
        { title: 'help.guide.title', href: HelpController.guide() },
    ],
};

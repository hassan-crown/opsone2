import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, LifeBuoy } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { ArticleCard } from '@/components/help/article-card';
import type { ArticleSummary } from '@/components/help/article-card';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export default function HelpCategory({
    category,
    articles,
}: {
    category: string;
    articles: ArticleSummary[];
}) {
    const t = useT();
    const title = t(`help.category.${category}` as TranslationKey);

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                back={
                    <Link
                        href={HelpController.index()}
                        className="inline-flex items-center gap-1 text-control"
                    >
                        <Icon
                            icon={ArrowLeft}
                            size="sm"
                            className="rtl:rotate-180"
                        />
                        {t('help.back')}
                    </Link>
                }
            />
            {articles.length === 0 ? (
                <Card>
                    <EmptyState icon={LifeBuoy} title={t('help.empty')} />
                </Card>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {articles.map((article) => (
                        <ArticleCard key={article.slug} article={article} />
                    ))}
                </div>
            )}
        </>
    );
}

HelpCategory.layout = {
    breadcrumbs: [{ title: 'help.title', href: HelpController.index() }],
};

import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { ArticleCard } from '@/components/help/article-card';
import type { ArticleSummary } from '@/components/help/article-card';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';

type Props = {
    article: ArticleSummary & {
        body: string;
        category: string;
        updated_at: string | null;
    };
    related: ArticleSummary[];
};

export default function HelpArticle({ article, related }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const updated = article.updated_at
        ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(
              new Date(article.updated_at),
          )
        : null;

    return (
        <>
            <Head title={article.title} />
            <PageHeader
                title={article.title}
                description={article.summary ?? undefined}
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
            <Card className="max-w-reading p-6">
                {/* Plain text only: paragraphs follow the article's line breaks; no HTML is ever rendered. */}
                <div
                    className="flex flex-col gap-3 text-body text-strong"
                    data-test="help-article-body"
                >
                    {article.body
                        .split('\n')
                        .map((line, index) =>
                            line.trim() === '' ? null : (
                                <p key={index}>{line}</p>
                            ),
                        )}
                </div>
                {updated && (
                    <p className="mt-6 text-caption text-weak">
                        {t('help.updated', { date: updated })}
                    </p>
                )}
            </Card>

            {related.length > 0 && (
                <section className="mt-8 flex max-w-reading flex-col gap-3">
                    <h2 className="text-subtitle text-strong">
                        {t('help.related')}
                    </h2>
                    {related.map((item) => (
                        <ArticleCard key={item.slug} article={item} />
                    ))}
                </section>
            )}
        </>
    );
}

HelpArticle.layout = {
    breadcrumbs: [{ title: 'help.title', href: HelpController.index() }],
};

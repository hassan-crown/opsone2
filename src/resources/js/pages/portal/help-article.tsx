import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ArticleBody } from '@/components/help/article-body';
import type { ArticleSummary } from '@/components/help/article-card';
import { Icon } from '@/components/opsone/icon';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/customer/help';

type Props = { article: ArticleSummary & { body_html: string } };

export default function PortalHelpArticle({ article }: Props) {
    const t = useT();

    return (
        <>
            <Head title={article.title} />
            <Link
                href={index().url}
                className="mb-4 inline-flex items-center gap-1 text-control"
            >
                <Icon icon={ArrowLeft} size="sm" />
                {t('help.back')}
            </Link>
            <h1 className="text-title text-strong">{article.title}</h1>
            {article.summary && (
                <p className="mt-1 text-body text-weak">{article.summary}</p>
            )}
            <Card className="mt-6 max-w-reading p-6">
                <ArticleBody html={article.body_html} />
            </Card>
        </>
    );
}

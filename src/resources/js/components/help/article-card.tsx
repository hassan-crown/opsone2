import { Link } from '@inertiajs/react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';

export type ArticleSummary = {
    slug: string;
    title: string;
    summary: string | null;
};

export function ArticleCard({ article }: { article: ArticleSummary }) {
    return (
        <Link
            href={HelpController.article.url({ article: article.slug })}
            className="flex flex-col gap-1.5 rounded-xl border border-stroke-weak bg-surface p-5 text-strong no-underline transition-colors hover:bg-hover"
        >
            <span className="text-control font-semibold">{article.title}</span>
            {article.summary && (
                <span className="text-body text-weak">{article.summary}</span>
            )}
        </Link>
    );
}

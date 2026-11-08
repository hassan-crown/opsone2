import { Link } from '@inertiajs/react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { LockedBadge } from '@/components/help/locked-badge';

export type ArticleSummary = {
    slug: string;
    title: string;
    summary: string | null;
    locked: boolean;
};

export function ArticleCard({
    article,
    href,
}: {
    article: ArticleSummary;
    /** Defaults to the staff article page; the portal passes its own. */
    href?: string;
}) {
    return (
        <Link
            href={href ?? HelpController.article.url({ article: article.slug })}
            className="flex flex-col gap-1.5 rounded-xl border border-stroke-weak bg-surface p-5 text-strong no-underline transition-colors hover:bg-hover"
        >
            <span className="flex flex-wrap items-center gap-2 text-control font-semibold">
                {article.title}
                {article.locked && <LockedBadge />}
            </span>
            {article.summary && (
                <span className="text-body text-weak">{article.summary}</span>
            )}
        </Link>
    );
}

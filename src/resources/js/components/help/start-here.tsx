import { Link, router } from '@inertiajs/react';
import { CircleCheck, Rocket, X } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import type { ArticleSummary } from '@/components/help/article-card';
import { LockedBadge } from '@/components/help/locked-badge';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

/**
 * «Start here»: the getting-started articles the user may read, until they close it (saved per user on the server).
 */
export function StartHere({ articles }: { articles: ArticleSummary[] }) {
    const t = useT();

    return (
        <Card className="p-5" data-test="help-start-here">
            <div className="flex items-start justify-between gap-4">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 className="flex items-center gap-2 text-subtitle text-strong">
                        <Icon icon={Rocket} size="sm" />
                        {t('help.start.title')}
                    </h2>
                    <p className="text-body text-weak">
                        {t('help.start.description')}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.post(
                            HelpController.dismissStartHere.url(),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    data-test="help-start-here-dismiss"
                >
                    <Icon icon={X} size="sm" />
                    {t('help.start.dismiss')}
                </Button>
            </div>
            <ol className="mt-4 flex flex-col gap-2">
                {articles.map((article) => (
                    <li key={article.slug}>
                        <Link
                            href={HelpController.article.url({
                                article: article.slug,
                            })}
                            className="flex items-center gap-2 text-control text-strong"
                        >
                            <Icon
                                icon={CircleCheck}
                                size="sm"
                                className="text-weak"
                            />
                            {article.title}
                            {article.locked && <LockedBadge />}
                        </Link>
                    </li>
                ))}
            </ol>
        </Card>
    );
}

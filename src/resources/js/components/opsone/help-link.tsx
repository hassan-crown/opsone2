import { Link } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { useState } from 'react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { ArticleBody } from '@/components/help/article-body';
import { LockedBadge } from '@/components/help/locked-badge';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';

type PanelArticle = {
    slug: string;
    title: string;
    summary: string | null;
    locked: boolean;
    body_html: string;
    url: string;
};

type PanelState =
    | { status: 'idle' | 'loading' | 'error' }
    | { status: 'ready'; article: PanelArticle | null };

/**
 * Contextual «?»: a side drawer with the help article for the current page (matched by the page's route name and
 * filtered by the server for this user), else a way into the help center.
 */
export function HelpLink() {
    const t = useT();
    const { currentUrl } = useCurrentUrl();
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<PanelState>({ status: 'idle' });
    const [loadedFor, setLoadedFor] = useState<string | null>(null);

    const load = async () => {
        setState({ status: 'loading' });
        const result = await api<{ article: PanelArticle | null }>(
            'GET',
            HelpController.panel.url({ query: { path: currentUrl } }),
        );
        if (result.ok && result.data) {
            setState({ status: 'ready', article: result.data.article });
            setLoadedFor(currentUrl);
        } else {
            setState({ status: 'error' });
        }
    };

    const onOpenChange = (next: boolean) => {
        setOpen(next);
        if (next && (loadedFor !== currentUrl || state.status === 'error')) {
            void load();
        }
    };

    const article = state.status === 'ready' ? state.article : null;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <Button
                variant="ghost"
                size="icon"
                className="text-strong"
                aria-label={t('help.contextual')}
                title={t('help.contextual')}
                data-test="help-link"
                onClick={() => onOpenChange(true)}
            >
                <Icon icon={CircleHelp} />
            </Button>
            <SheetContent
                className="flex w-full flex-col gap-0 sm:max-w-md"
                data-test="help-panel"
            >
                <SheetHeader className="border-b border-stroke-weak">
                    <SheetTitle className="flex flex-wrap items-center gap-2">
                        {article ? article.title : t('help.contextual')}
                        {article?.locked && <LockedBadge />}
                    </SheetTitle>
                    <SheetDescription>
                        {article?.summary ?? t('help.description')}
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {state.status === 'loading' && (
                        <p className="flex items-center gap-2 text-body text-weak">
                            <Spinner />
                            {t('help.panel.loading')}
                        </p>
                    )}
                    {state.status === 'error' && (
                        <p className="text-body text-danger-text">
                            {t('help.panel.error')}
                        </p>
                    )}
                    {state.status === 'ready' &&
                        (article ? (
                            <ArticleBody html={article.body_html} />
                        ) : (
                            <p className="text-body text-weak">
                                {t('help.panel.none')}
                            </p>
                        ))}
                </div>
                <div className="flex flex-wrap gap-2 border-t border-stroke-weak p-4">
                    {article && (
                        <Button asChild onClick={() => setOpen(false)}>
                            <Link href={article.url}>
                                {t('help.panel.open_article')}
                            </Link>
                        </Button>
                    )}
                    <Button
                        variant="secondary"
                        asChild
                        onClick={() => setOpen(false)}
                    >
                        <Link href={HelpController.index()}>
                            {t('help.panel.open_center')}
                        </Link>
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

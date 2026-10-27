import { Link } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/Help/HelpController';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useT } from '@/lib/i18n';

/** Contextual «?»: opens the help article that explains the current page, else the help center. */
export function HelpLink() {
    const t = useT();
    const { currentUrl } = useCurrentUrl();

    return (
        <Button variant="ghost" size="icon" className="text-strong" asChild>
            <Link
                href={HelpController.context.url({
                    query: { path: currentUrl },
                })}
                aria-label={t('help.contextual')}
                title={t('help.contextual')}
                data-test="help-link"
            >
                <Icon icon={CircleHelp} />
            </Link>
        </Button>
    );
}

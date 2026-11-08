import { Lock } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';

/** «On a higher plan»: the server only sends locked articles to the owner and managers. */
export function LockedBadge() {
    const t = useT();

    return (
        <Badge variant="info" data-test="help-locked">
            <Icon icon={Lock} size="sm" />
            {t('help.locked')}
        </Badge>
    );
}

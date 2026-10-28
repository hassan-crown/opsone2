import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

/**
 * OrderStale: someone else changed the order while this form was open (optimistic locking, 409). What the user
 * typed was not saved; reloading shows the latest version.
 */
export function ConflictAlert({ onReload }: { onReload?: () => void }) {
    const t = useT();

    return (
        <Alert variant="danger" role="alert" data-test="stale-conflict">
            <AlertBody>
                <AlertTitle>{t('orders.conflict.title')}</AlertTitle>
                <p>{t('orders.conflict.body')}</p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    className="mt-3"
                    data-test="stale-reload"
                    onClick={() => {
                        onReload?.();
                        router.reload();
                    }}
                >
                    <Icon icon={RefreshCw} />
                    {t('orders.conflict.reload')}
                </Button>
            </AlertBody>
        </Alert>
    );
}

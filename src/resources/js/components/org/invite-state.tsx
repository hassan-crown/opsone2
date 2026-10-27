import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { login } from '@/routes';

/** Expired / used / cancelled / invalid invitation link. */
export function InviteState({
    state,
}: {
    state: 'expired' | 'used' | 'cancelled' | 'invalid';
}) {
    const t = useT();

    return (
        <Card data-test={`invite-state-${state}`}>
            <EmptyState
                icon={TriangleAlert}
                tone="danger"
                title={t(`org.accept.state_title_${state}`)}
                description={t(`org.accept.state_${state}`)}
                action={
                    <Button asChild variant="secondary">
                        <Link href={login()}>{t('org.accept.go_login')}</Link>
                    </Button>
                }
            />
        </Card>
    );
}

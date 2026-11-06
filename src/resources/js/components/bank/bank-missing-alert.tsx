import { Link } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { bank } from '@/routes/settings';

/** ADR-0046: the facility has no active bank account, so customers are not told where to transfer. */
export function BankMissingAlert({ className }: { className?: string }) {
    const t = useT();

    return (
        <Alert variant="warning" className={className} data-test="bank-missing">
            <Icon icon={Landmark} />
            <AlertBody>
                <AlertTitle>{t('bank.missing.title')}</AlertTitle>
                <AlertDescription>{t('bank.missing.body')}</AlertDescription>
                <Button asChild size="sm" variant="outline" className="mt-2">
                    <Link href={bank().url}>{t('bank.missing.action')}</Link>
                </Button>
            </AlertBody>
        </Alert>
    );
}

import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { home } from '@/routes/customer';
import { show } from '@/routes/customer/documents';

/**
 * A signing link that no longer leads to a signature (ADR-0029): expired, revoked or already used up — or not this
 * customer's, which reads as «not valid» without saying whether it exists.
 */
export default function PortalSignLink({
    state,
    document_id,
}: {
    state: 'invalid' | 'expired';
    document_id?: number;
}) {
    const t = useT();
    const title =
        state === 'expired'
            ? t('esign.ui.link_expired_title')
            : t('esign.ui.link_invalid_title');

    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex max-w-md flex-col gap-4">
                <h1 className="text-title text-strong">{title}</h1>
                <Card className="flex flex-col gap-4">
                    <p className="text-body text-strong">
                        {state === 'expired'
                            ? t('esign.ui.link_expired')
                            : t('esign.ui.link_invalid')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {document_id !== undefined && (
                            <Button asChild>
                                <Link href={show(document_id).url}>
                                    {t('esign.ui.open_document')}
                                </Link>
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <Link href={home().url}>
                                {t('esign.ui.back_home')}
                            </Link>
                        </Button>
                    </div>
                </Card>
            </div>
        </>
    );
}

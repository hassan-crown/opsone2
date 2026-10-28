import { Form, Head, Link } from '@inertiajs/react';
import { TextareaField } from '@/components/opsone/field';
import { Section, StatusBadge } from '@/components/portal/parts';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { store, withdraw } from '@/routes/customer/cancellations';
import { show as showOrder } from '@/routes/customer/orders';
import type { PortalCancellation, PortalOrder } from '@/types/portal';

type Props = {
    order: PortalOrder;
    cancellation: PortalCancellation | null;
    can_request: boolean;
};

/** CancelRequest (P-99, WEB-13): the customer asks with a reason; the facility reviews and decides. */
export default function PortalCancel({
    order,
    cancellation,
    can_request,
}: Props) {
    const t = useT();
    const back = showOrder(order.id).url;

    return (
        <>
            <Head title={t('portal.cancel.title')} />
            <Link
                href={back}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                <bdi dir="ltr">{order.number}</bdi>
            </Link>
            <h1 className="text-title text-strong">
                {t('portal.cancel.title')}
            </h1>
            <p className="mt-1 mb-6 text-body text-weak">
                <bdi dir="ltr">{order.number}</bdi>
                {order.title && (
                    <>
                        {t('portal.sep')}
                        {order.title}
                    </>
                )}
            </p>

            {cancellation && cancellation.status === 'pending' && (
                <Card className="mb-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <bdi dir="ltr" className="text-label text-strong">
                            {cancellation.number}
                        </bdi>
                        <StatusBadge
                            scope="cancellation"
                            status={cancellation.status}
                        />
                    </div>
                    <p className="text-body text-weak">
                        {t(
                            `portal.cancel.status_note.${cancellation.status}` as TranslationKey,
                        )}
                    </p>
                    {cancellation.can_withdraw && (
                        <Form {...withdraw.form(cancellation.id)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    {t('portal.cancel.withdraw')}
                                </Button>
                            )}
                        </Form>
                    )}
                </Card>
            )}

            {can_request ? (
                <>
                    <Section title={t('portal.cancel.before')}>
                        <ul className="list-disc ps-5 text-body text-strong">
                            <li>{t('portal.cancel.before_1')}</li>
                            <li>{t('portal.cancel.before_2')}</li>
                            <li>{t('portal.cancel.before_3')}</li>
                        </ul>
                    </Section>
                    <Card>
                        <Form
                            {...store.form(order.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <TextareaField
                                        label={t('portal.cancel.reason')}
                                        name="reason"
                                        required
                                        rows={4}
                                        maxLength={1000}
                                        error={
                                            errors.reason ?? errors.transition
                                        }
                                    />
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('portal.cancel.send')}
                                        </Button>
                                        <Link
                                            href={back}
                                            className={buttonVariants({
                                                variant: 'ghost',
                                            })}
                                        >
                                            {t('ui.back')}
                                        </Link>
                                    </div>
                                </>
                            )}
                        </Form>
                    </Card>
                </>
            ) : (
                !cancellation && (
                    <p className="text-body text-weak">
                        {t('portal.cancel.not_available')}
                    </p>
                )
            )}
        </>
    );
}

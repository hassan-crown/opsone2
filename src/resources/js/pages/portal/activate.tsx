import { Form, Head } from '@inertiajs/react';
import { FieldError } from '@/components/opsone/field';
import { usePortal } from '@/layouts/portal-layout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';

type Props = {
    name: string;
    destination: string | null;
    activated: boolean;
    send_url: string;
};

/** ActivateAccount: the link staff sent; a code to the account's own mobile or email completes it. */
export default function PortalActivate({
    name,
    destination,
    activated,
    send_url,
}: Props) {
    const t = useT();
    const { facility } = usePortal();

    return (
        <>
            <Head title={t('portal.activate.title')} />
            <div className="mx-auto flex max-w-md flex-col gap-6">
                <div>
                    <h1 className="text-title text-strong">
                        {t('portal.activate.hello', { name })}
                    </h1>
                    <p className="mt-1 text-body text-weak">
                        {activated
                            ? t('portal.activate.already')
                            : t('portal.activate.lead', {
                                  facility: facility ?? '',
                              })}
                    </p>
                </div>
                <Card>
                    {destination === null ? (
                        <p className="text-body text-strong">
                            {t('portal.activate.no_channel')}
                        </p>
                    ) : (
                        <Form
                            action={send_url}
                            method="post"
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <p className="text-body text-strong">
                                        {t('portal.activate.destination')}{' '}
                                        <bdi dir="ltr">{destination}</bdi>
                                    </p>
                                    {errors.code && (
                                        <FieldError>{errors.code}</FieldError>
                                    )}
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('portal.activate.send')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </Card>
                <ul className="list-disc ps-5 text-body text-weak">
                    <li>{t('portal.login.after_own')}</li>
                    <li>{t('portal.login.after_name')}</li>
                </ul>
            </div>
        </>
    );
}

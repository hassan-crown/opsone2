import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const t = useT();

    return (
        <>
            <Head title={t('auth.verify_title')} />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-label text-success-text">
                    {t('auth.verification_sent')}
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button loading={processing} variant="secondary">
                            {processing && <Spinner />}
                            {t('auth.resend_verification')}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-label"
                        >
                            {t('nav.logout')}
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'auth.verify_title',
    description: 'auth.verify_description',
};

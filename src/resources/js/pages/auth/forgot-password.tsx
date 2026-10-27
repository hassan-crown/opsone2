import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const t = useT();

    return (
        <>
            <Head title={t('auth.forgot_title')} />

            {status && (
                <div className="mb-4 text-center text-label text-success-text">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form {...email.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <TextField
                                label={t('auth.email')}
                                type="email"
                                name="email"
                                dir="ltr"
                                autoComplete="off"
                                inputMode="email"
                                autoFocus
                                placeholder={t('auth.email_placeholder')}
                                error={errors.email}
                            />

                            <Button
                                block
                                loading={processing}
                                data-test="email-password-reset-link-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.send_reset_link')}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="text-center text-body text-weak">
                    <span>{t('auth.return_to')}</span>{' '}
                    <TextLink href={login()}>{t('auth.log_in_lower')}</TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'auth.forgot_title',
    description: 'auth.forgot_description',
};

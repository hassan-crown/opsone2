import { Form, Head } from '@inertiajs/react';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { CheckboxField, Field, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('auth.login')} />

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <TextField
                                label={t('auth.email')}
                                type="email"
                                name="email"
                                dir="ltr"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                inputMode="email"
                                placeholder={t('auth.email_placeholder')}
                                error={errors.email}
                            />

                            <Field
                                label={t('auth.password')}
                                error={errors.password}
                                required
                            >
                                {(control) => (
                                    <>
                                        <PasswordInput
                                            {...control}
                                            name="password"
                                            tabIndex={2}
                                            autoComplete="current-password"
                                        />
                                        {canResetPassword && (
                                            <TextLink
                                                href={request()}
                                                className="self-start text-label"
                                                tabIndex={5}
                                            >
                                                {t('auth.forgot_password_link')}
                                            </TextLink>
                                        )}
                                    </>
                                )}
                            </Field>

                            <CheckboxField
                                name="remember"
                                label={t('auth.remember_me')}
                                tabIndex={3}
                            />

                            <Button
                                type="submit"
                                block
                                tabIndex={4}
                                loading={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.login')}
                            </Button>
                        </div>

                        <p className="text-center text-body text-weak">
                            {t('auth.invite_only')}
                        </p>
                    </>
                )}
            </Form>

            {status && (
                <div className="mt-4 text-center text-label text-success-text">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'auth.login_title',
    description: 'auth.login_description',
};

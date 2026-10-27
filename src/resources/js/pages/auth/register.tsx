import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Field, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('auth.register')} />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <TextField
                                label={t('auth.name')}
                                name="name"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="name"
                                placeholder={t('auth.full_name')}
                                error={errors.name}
                            />
                            <TextField
                                label={t('auth.email')}
                                type="email"
                                name="email"
                                dir="ltr"
                                required
                                tabIndex={2}
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
                                    <PasswordInput
                                        {...control}
                                        name="password"
                                        tabIndex={3}
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                    />
                                )}
                            </Field>
                            <Field
                                label={t('auth.confirm_password')}
                                error={errors.password_confirmation}
                                required
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        name="password_confirmation"
                                        tabIndex={4}
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                    />
                                )}
                            </Field>

                            <Button
                                type="submit"
                                block
                                tabIndex={5}
                                loading={processing}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.create_account')}
                            </Button>
                        </div>

                        <div className="text-center text-body text-weak">
                            {t('auth.have_account')}{' '}
                            <TextLink href={login()} tabIndex={6}>
                                {t('auth.login')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'auth.register_title',
    description: 'auth.register_description',
};

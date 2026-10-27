import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import { Field, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('auth.reset_title')} />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <TextField
                            label={t('auth.email')}
                            type="email"
                            name="email"
                            dir="ltr"
                            autoComplete="email"
                            value={email}
                            readOnly
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
                                    autoComplete="new-password"
                                    autoFocus
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
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                />
                            )}
                        </Field>

                        <Button
                            type="submit"
                            block
                            loading={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            {t('auth.reset_title')}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'auth.reset_title',
    description: 'auth.reset_description',
};

import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/opsone/field';
import { useT } from '@/lib/i18n';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const t = useT();
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title={t('settings.security_title')} />

            <h1 className="sr-only">{t('settings.security_title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.update_password')}
                    description={t('settings.update_password_description')}
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                label={t('settings.current_password')}
                                error={errors.current_password}
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        autoComplete="current-password"
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('settings.new_password')}
                                error={errors.password}
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        ref={passwordInput}
                                        name="password"
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('auth.confirm_password')}
                                error={errors.password_confirmation}
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />
                                )}
                            </Field>

                            <div className="flex items-center gap-4">
                                <Button
                                    loading={processing}
                                    data-test="update-password-button"
                                >
                                    {t('ui.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'settings.security_title',
            href: edit(),
        },
    ],
};

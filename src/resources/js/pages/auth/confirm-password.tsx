import { Form, Head } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Field } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const t = useT();

    return (
        <>
            <Head title={t('auth.confirm_title')} />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label={t('auth.confirm_with_passkey')}
                loadingLabel={t('auth.confirming')}
                separator={t('auth.or_confirm_password')}
            />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <Field
                            label={t('auth.password')}
                            error={errors.password}
                            required
                        >
                            {(control) => (
                                <PasswordInput
                                    {...control}
                                    name="password"
                                    autoComplete="current-password"
                                    autoFocus
                                />
                            )}
                        </Field>

                        <Button
                            block
                            loading={processing}
                            data-test="confirm-password-button"
                        >
                            {processing && <Spinner />}
                            {t('auth.confirm_title')}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'auth.confirm_title',
    description: 'auth.confirm_description',
};

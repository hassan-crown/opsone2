import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { Field, TextField } from '@/components/opsone/field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type FormTarget = { action: string; method: 'post' | 'get' };

/**
 * Ownership proof (emailed code) + name + password. Shared by the owner and staff invitation pages;
 * the server decides what is accepted, this only collects it.
 */
export function AcceptForm({
    submitLabel,
    sendCodeUrl,
    maskedEmail,
    codeSent,
    resendSeconds,
    defaultName,
    accept,
}: {
    submitLabel: string;
    sendCodeUrl: string;
    maskedEmail: string;
    codeSent: boolean;
    resendSeconds: number;
    defaultName: string | null;
    accept: FormTarget;
}) {
    const t = useT();
    const [sending, setSending] = useState(false);

    const sendCode = () => {
        router.post(
            sendCodeUrl,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <Form
            {...accept}
            data-test="accept-form"
            resetOnSuccess={['password', 'password_confirmation']}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-col gap-2">
                        <TextField
                            label={t('org.accept.code_label')}
                            name="code"
                            dir="ltr"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            required
                            hint={
                                codeSent
                                    ? t('org.accept.code_hint', {
                                          email: maskedEmail,
                                          seconds: resendSeconds,
                                      })
                                    : t('org.accept.code_hint_unsent', {
                                          email: maskedEmail,
                                      })
                            }
                            error={errors.code}
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="self-start"
                            loading={sending}
                            onClick={sendCode}
                            data-test="send-code"
                        >
                            {codeSent
                                ? t('org.accept.resend_code')
                                : t('org.accept.send_code')}
                        </Button>
                    </div>

                    <TextField
                        label={t('org.accept.name')}
                        name="name"
                        defaultValue={defaultName ?? ''}
                        autoComplete="name"
                        required
                        error={errors.name}
                    />
                    <Field
                        label={t('org.accept.password')}
                        hint={t('org.accept.password_hint')}
                        error={errors.password}
                        required
                    >
                        {(control) => (
                            <PasswordInput
                                {...control}
                                name="password"
                                autoComplete="new-password"
                            />
                        )}
                    </Field>
                    <Field label={t('org.accept.password_confirm')} required>
                        {(control) => (
                            <PasswordInput
                                {...control}
                                name="password_confirmation"
                                autoComplete="new-password"
                            />
                        )}
                    </Field>
                    {errors.token && (
                        <p
                            role="alert"
                            className="text-caption text-danger-text"
                        >
                            {errors.token}
                        </p>
                    )}
                    <Button
                        block
                        loading={processing}
                        data-test="accept-submit"
                    >
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}

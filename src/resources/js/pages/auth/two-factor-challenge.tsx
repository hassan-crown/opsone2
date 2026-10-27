import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/opsone/field';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const t = useT();
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: TranslationKey;
        description: TranslationKey;
        toggleText: TranslationKey;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'tf.recovery_title',
                description: 'tf.recovery_description',
                toggleText: 'tf.use_code',
            };
        }

        return {
            title: 'tf.code_title',
            description: 'tf.code_description',
            toggleText: 'tf.use_recovery',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title={t('tf.page_title')} />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <>
                                    <TextField
                                        label={t('tf.recovery_title')}
                                        name="recovery_code"
                                        type="text"
                                        dir="ltr"
                                        placeholder={t('tf.enter_recovery')}
                                        autoFocus={showRecoveryInput}
                                        required
                                        error={errors.recovery_code}
                                    />
                                </>
                            ) : (
                                <div
                                    dir="ltr"
                                    className="flex flex-col items-center justify-center space-y-3 text-center"
                                >
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            autoFocus
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError message={errors.code} />
                                </div>
                            )}

                            <Button type="submit" block loading={processing}>
                                {t('ui.continue')}
                            </Button>

                            <div className="text-center text-body text-weak">
                                <span>{t('tf.or_you_can')} </span>
                                <button
                                    type="button"
                                    className="cursor-pointer rounded-xs text-link underline underline-offset-4 hover:text-brand-hover"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {t(authConfigContent.toggleText)}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

import { Form, Head, Link } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useEffect, useState } from 'react';
import { FieldError } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { login } from '@/routes/customer';
import { resend } from '@/routes/customer/login';
import { store } from '@/routes/customer/login/verify';

type Props = {
    identifier: string;
    digits: number;
    ttl_minutes: number;
    resend_after: number;
};

/** Customer sign-in, step 2: the one-time code. The page never says whether the account exists. */
export default function PortalVerify({
    identifier,
    digits,
    ttl_minutes,
    resend_after,
}: Props) {
    const t = useT();
    const [code, setCode] = useState('');
    const [wait, setWait] = useState(resend_after);

    useEffect(() => {
        if (wait <= 0) {
            return;
        }
        const timer = window.setTimeout(() => setWait(wait - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [wait]);

    return (
        <>
            <Head title={t('portal.verify.title')} />
            <div className="mx-auto flex max-w-md flex-col gap-6">
                <div>
                    <h1 className="text-title text-strong">
                        {t('portal.verify.title')}
                    </h1>
                    <p className="mt-1 text-body text-weak">
                        {t('portal.verify.lead', { minutes: ttl_minutes })}{' '}
                        <bdi dir="ltr" className="text-strong">
                            {identifier}
                        </bdi>
                    </p>
                </div>

                <Card>
                    <Form
                        {...store.form()}
                        className="flex flex-col gap-4"
                        resetOnError
                    >
                        {({ processing, errors }) => (
                            <>
                                <label
                                    htmlFor="code"
                                    className="text-label text-strong"
                                >
                                    {t('portal.verify.code')}
                                </label>
                                <div dir="ltr" className="flex justify-center">
                                    <InputOTP
                                        id="code"
                                        name="code"
                                        maxLength={digits}
                                        value={code}
                                        onChange={setCode}
                                        pattern={REGEXP_ONLY_DIGITS}
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        autoFocus
                                        disabled={processing}
                                        aria-invalid={
                                            errors.code ? true : undefined
                                        }
                                    >
                                        <InputOTPGroup>
                                            {Array.from(
                                                { length: digits },
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
                                {errors.code && (
                                    <FieldError>{errors.code}</FieldError>
                                )}
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || code.length < digits
                                    }
                                >
                                    {processing && <Spinner />}
                                    {t('portal.verify.submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                </Card>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Form
                        {...resend.form()}
                        onSuccess={() => setWait(resend_after)}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing || wait > 0}
                            >
                                {wait > 0
                                    ? t('portal.verify.resend_in', {
                                          seconds: wait,
                                      })
                                    : t('portal.verify.resend')}
                            </Button>
                        )}
                    </Form>
                    <Link
                        href={login().url}
                        className="text-label text-link underline-offset-4 hover:underline"
                    >
                        {t('portal.verify.change')}
                    </Link>
                </div>
            </div>
        </>
    );
}

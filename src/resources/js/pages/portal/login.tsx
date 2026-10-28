import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { usePortal } from '@/layouts/portal-layout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { send } from '@/routes/customer/login';

/** Customer sign-in, step 1 (WEB-08): mobile or email; no password. */
export default function PortalLogin({ ttl_minutes }: { ttl_minutes: number }) {
    const t = useT();
    const { facility } = usePortal();

    return (
        <>
            <Head title={t('portal.login.title')} />
            <div className="mx-auto flex max-w-md flex-col gap-6">
                <div>
                    <h1 className="text-title text-strong">
                        {t('portal.login.title')}
                    </h1>
                    <p className="mt-1 text-body text-weak">
                        {t('portal.login.lead', {
                            facility: facility ?? '',
                        })}
                    </p>
                </div>

                <Card>
                    <Form {...send.form()} className="flex flex-col gap-4">
                        {({ processing, errors }) => (
                            <>
                                <TextField
                                    label={t('portal.login.identifier')}
                                    hint={t('portal.login.identifier_hint')}
                                    name="identifier"
                                    dir="ltr"
                                    required
                                    autoFocus
                                    autoComplete="username"
                                    inputMode="text"
                                    placeholder="05XXXXXXXX"
                                    error={errors.identifier}
                                />
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('portal.login.send')}
                                </Button>
                                <p className="text-caption text-weak">
                                    {t('portal.login.ttl', {
                                        minutes: ttl_minutes,
                                    })}
                                </p>
                            </>
                        )}
                    </Form>
                </Card>

                <div className="flex gap-3 rounded-lg bg-fill p-4">
                    <Icon icon={ShieldCheck} className="mt-0.5 text-weak" />
                    <div>
                        <p className="text-label text-strong">
                            {t('portal.login.after')}
                        </p>
                        <ul className="mt-1 list-disc ps-5 text-body text-weak">
                            <li>{t('portal.login.after_own')}</li>
                            <li>{t('portal.login.after_name')}</li>
                        </ul>
                    </div>
                </div>
            </div>
        </>
    );
}

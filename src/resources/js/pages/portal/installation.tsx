import { Head, Link, useForm } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import {
    CheckboxField,
    FieldError,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { SignaturePad } from '@/components/portal/sign-panel';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import { sign } from '@/routes/customer/installations';
import { show as orderShow } from '@/routes/customer/orders';

type Installation = {
    id: number;
    order: { id: number; number: string };
    date: string;
    items: string[];
    signable: boolean;
    signed: boolean;
    signer_name: string | null;
    signed_at: string | null;
};

/**
 * The customer accepts an installation from their workspace (ADR-0036), when the technician sent the link
 * instead of signing on the phone: the items, a typed name, a drawn signature and the acceptance statement.
 */
export default function PortalInstallation({
    installation,
}: {
    installation: Installation;
}) {
    const t = useT();
    const form = useForm({ signer_name: '', signature: '', consent: false });

    return (
        <>
            <Head title={t('installation.portal.title')} />
            <div className="mx-auto flex max-w-xl flex-col gap-4">
                <h1 className="text-title text-strong">
                    {t('installation.portal.title')}
                </h1>
                <Card className="flex flex-col gap-2">
                    <p className="m-0 text-body text-strong">
                        {t('installation.portal.order', {
                            order: installation.order.number,
                            date: installation.date,
                        })}
                    </p>
                    {installation.items.length > 0 && (
                        <ul className="m-0 ps-5 text-body">
                            {installation.items.map((item, i) => (
                                <li key={i}>{item}</li>
                            ))}
                        </ul>
                    )}
                </Card>

                {installation.signed && (
                    <Card className="flex items-start gap-2">
                        <Icon
                            icon={CircleCheck}
                            className="text-success-text"
                        />
                        <p className="m-0 text-body">
                            {t('installation.portal.signed', {
                                name: installation.signer_name ?? '',
                                at: installation.signed_at ?? '',
                            })}
                        </p>
                    </Card>
                )}

                {!installation.signed && !installation.signable && (
                    <Card>
                        <p className="m-0 text-body">
                            {t('installation.portal.not_signable')}
                        </p>
                    </Card>
                )}

                {installation.signable && (
                    <Card>
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(sign(installation.id).url);
                            }}
                        >
                            <TextField
                                label={t('field.signature.name')}
                                value={form.data.signer_name}
                                onChange={(e) =>
                                    form.setData('signer_name', e.target.value)
                                }
                                error={form.errors.signer_name}
                                required
                            />
                            <SignaturePad
                                label={t('field.signature.pad')}
                                clearLabel={t('field.signature.clear')}
                                invalid={Boolean(form.errors.signature)}
                                onChange={(data) =>
                                    form.setData('signature', data ?? '')
                                }
                            />
                            {form.errors.signature && (
                                <FieldError>{form.errors.signature}</FieldError>
                            )}
                            <CheckboxField
                                label={t('field.signature.consent')}
                                checked={form.data.consent}
                                onChange={(e) =>
                                    form.setData('consent', e.target.checked)
                                }
                                error={form.errors.consent}
                            />
                            <Button
                                type="submit"
                                loading={form.processing}
                                disabled={
                                    !form.data.signature || !form.data.consent
                                }
                            >
                                {t('field.signature.save')}
                            </Button>
                        </form>
                    </Card>
                )}

                <Link
                    href={orderShow(installation.order.id).url}
                    className="text-label text-link"
                >
                    {t('installation.portal.back')}
                </Link>
            </div>
        </>
    );
}

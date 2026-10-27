import { Head } from '@inertiajs/react';
import OwnerInviteController from '@/actions/App/Http/Controllers/Org/OwnerInviteController';
import { AcceptForm } from '@/components/org/accept-form';
import { InviteState } from '@/components/org/invite-state';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = {
    state: 'valid' | 'expired' | 'used' | 'cancelled' | 'invalid';
    token: string;
    invitee: {
        name: string | null;
        email: string;
        masked_email: string;
        code_sent: boolean;
    } | null;
    facility: string | null;
    plan: string | null;
    resendSeconds: number;
};

export default function OwnerInvite({
    state,
    token,
    invitee,
    facility,
    plan,
    resendSeconds,
}: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('org.owner.title_anon')} />
            {state !== 'valid' || invitee === null ? (
                <InviteState state={state === 'valid' ? 'invalid' : state} />
            ) : (
                <div className="flex flex-col gap-6">
                    <p className="text-center text-body text-weak">
                        {invitee.name
                            ? t('org.owner.title', { name: invitee.name })
                            : t('org.owner.title_anon')}
                        {' — '}
                        {t('org.owner.subtitle', { facility: facility ?? '' })}
                    </p>
                    <dl className="m-0 grid grid-cols-1 gap-px overflow-hidden rounded-md border border-stroke-weak bg-stroke-weak sm:grid-cols-3">
                        {[
                            [t('org.owner.facility'), facility ?? '—'],
                            [t('org.owner.plan'), plan ?? '—'],
                            [t('org.owner.email'), invitee.masked_email],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="flex min-w-0 flex-col gap-0.5 bg-raised px-3 py-2.5"
                            >
                                <dt className="text-caption text-weak">
                                    {label}
                                </dt>
                                <dd
                                    className="m-0 truncate font-semibold text-strong"
                                    dir="auto"
                                >
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <Card>
                        <AcceptForm
                            accept={OwnerInviteController.accept.form({
                                token,
                            })}
                            submitLabel={t('org.owner.submit')}
                            sendCodeUrl={OwnerInviteController.sendCode.url({
                                token,
                            })}
                            maskedEmail={invitee.masked_email}
                            codeSent={invitee.code_sent}
                            resendSeconds={resendSeconds}
                            defaultName={invitee.name}
                        />
                    </Card>
                    <p className="text-center text-caption text-weak">
                        {t('org.owner.footer')}
                    </p>
                </div>
            )}
        </>
    );
}

OwnerInvite.layout = {
    title: 'org.owner.title_anon',
};

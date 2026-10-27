import { Head } from '@inertiajs/react';
import InviteAcceptController from '@/actions/App/Http/Controllers/Org/InviteAcceptController';
import { AcceptForm } from '@/components/org/accept-form';
import { formatDate } from '@/components/org/format';
import { InviteState } from '@/components/org/invite-state';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';

type Props = {
    state: 'valid' | 'expired' | 'used' | 'cancelled' | 'invalid';
    token: string;
    invitation: {
        name: string | null;
        masked_email: string;
        code_sent: boolean;
        inviter: string | null;
        roles: string[];
        department: string | null;
        branches: string[];
        expires_at: string;
    } | null;
    facility: string | null;
    resendSeconds: number;
};

export default function InviteAccept({
    state,
    token,
    invitation,
    facility,
    resendSeconds,
}: Props) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <>
            <Head title={t('org.invite_accept.yours')} />
            {state !== 'valid' || invitation === null ? (
                <InviteState state={state === 'valid' ? 'invalid' : state} />
            ) : (
                <div className="flex flex-col gap-6">
                    <div className="space-y-1 text-center">
                        <h2 className="text-subtitle text-strong">
                            {t('org.invite_accept.title', {
                                facility: facility ?? '',
                            })}
                        </h2>
                        <p className="text-body text-weak">
                            {invitation.inviter
                                ? t('org.invite_accept.subtitle', {
                                      inviter: invitation.inviter,
                                      date: formatDate(
                                          invitation.expires_at,
                                          locale,
                                      ),
                                  })
                                : t('org.invite_accept.subtitle_anon', {
                                      date: formatDate(
                                          invitation.expires_at,
                                          locale,
                                      ),
                                  })}
                        </p>
                    </div>
                    <dl className="m-0 grid gap-px overflow-hidden rounded-md border border-stroke-weak bg-stroke-weak">
                        {[
                            [
                                t('org.invite_accept.role'),
                                invitation.roles.join('، '),
                            ],
                            [
                                t('org.invite_accept.department'),
                                invitation.department,
                            ],
                            [
                                t('org.invite_accept.scope'),
                                invitation.branches.join('، '),
                            ],
                        ]
                            .filter(([, value]) => value)
                            .map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex items-baseline justify-between gap-4 bg-raised px-3 py-2.5"
                                >
                                    <dt className="text-caption text-weak">
                                        {label}
                                    </dt>
                                    <dd className="m-0 font-semibold text-strong">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                    </dl>
                    <Card>
                        <AcceptForm
                            accept={InviteAcceptController.accept.form({
                                token,
                            })}
                            submitLabel={t('org.invite_accept.submit')}
                            sendCodeUrl={InviteAcceptController.sendCode.url({
                                token,
                            })}
                            maskedEmail={invitation.masked_email}
                            codeSent={invitation.code_sent}
                            resendSeconds={resendSeconds}
                            defaultName={invitation.name}
                        />
                    </Card>
                    <p className="text-center text-caption text-weak">
                        {t('org.invite_accept.footer')}
                    </p>
                </div>
            )}
        </>
    );
}

InviteAccept.layout = {
    title: 'org.invite_accept.yours',
};

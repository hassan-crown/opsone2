import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import { TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const t = useT();
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title={t('settings.profile_title')} />

            <h1 className="sr-only">{t('settings.profile_title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.profile')}
                    description={t('settings.profile_description')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <TextField
                                label={t('auth.name')}
                                defaultValue={auth.user.name}
                                name="name"
                                required
                                autoComplete="name"
                                error={errors.name}
                            />

                            <TextField
                                label={t('auth.email')}
                                type="email"
                                dir="ltr"
                                defaultValue={auth.user.email}
                                name="email"
                                required
                                autoComplete="username"
                                inputMode="email"
                                error={errors.email}
                            />

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="text-body text-weak">
                                            {t('settings.unverified')}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="rounded-xs text-link underline underline-offset-4 hover:text-brand-hover"
                                            >
                                                {t(
                                                    'settings.resend_verification',
                                                )}
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-label text-success-text">
                                                {t(
                                                    'settings.verification_sent',
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    loading={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('ui.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'settings.profile_title',
            href: edit(),
        },
    ],
};

import { Form, Head, router } from '@inertiajs/react';
import { DateText, Section } from '@/components/portal/parts';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';
import type { Locale } from '@/lib/i18n';
import { locale as localeRoute, logout } from '@/routes/customer';
import { everywhere } from '@/routes/customer/logout';

type Props = {
    account: {
        name: string;
        mobile: string;
        email: string | null;
        email_verified: boolean;
        locale: string | null;
        last_login_at: string | null;
    };
};

const NAMES: Record<Locale, string> = { ar: 'العربية', en: 'English' };

/** Customer settings (P-65): identity as held by the facility, language, and sessions. */
export default function PortalSettings({ account }: Props) {
    const t = useT();
    const { locale, locales } = useLocale();

    return (
        <>
            <Head title={t('portal.nav.settings')} />
            <h1 className="mb-6 text-title text-strong">
                {t('portal.nav.settings')}
            </h1>

            <Section title={t('portal.settings.account')}>
                <Card>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-body">
                        <dt className="text-weak">
                            {t('portal.settings.name')}
                        </dt>
                        <dd className="text-strong">{account.name}</dd>
                        <dt className="text-weak">
                            {t('portal.settings.mobile')}
                        </dt>
                        <dd className="text-strong">
                            <bdi dir="ltr">{account.mobile}</bdi>
                        </dd>
                        <dt className="text-weak">
                            {t('portal.settings.email')}
                        </dt>
                        <dd className="text-strong">
                            {account.email ? (
                                <bdi dir="ltr">{account.email}</bdi>
                            ) : (
                                '—'
                            )}
                            {account.email && (
                                <span className="ms-2 text-caption text-weak">
                                    {account.email_verified
                                        ? t('portal.settings.verified')
                                        : t('portal.settings.unverified')}
                                </span>
                            )}
                        </dd>
                    </dl>
                    <p className="text-caption text-weak">
                        {t('portal.settings.change_note')}
                    </p>
                </Card>
            </Section>

            <Section title={t('portal.settings.language')}>
                <div
                    className="flex gap-2"
                    role="radiogroup"
                    aria-label={t('portal.settings.language')}
                >
                    {locales.map((candidate) => (
                        <Button
                            key={candidate}
                            type="button"
                            role="radio"
                            aria-checked={candidate === locale}
                            variant={
                                candidate === locale ? 'default' : 'outline'
                            }
                            lang={candidate}
                            onClick={() =>
                                router.put(localeRoute().url, {
                                    locale: candidate,
                                })
                            }
                        >
                            {NAMES[candidate]}
                        </Button>
                    ))}
                </div>
            </Section>

            <Section title={t('portal.settings.sessions')}>
                <Card>
                    {account.last_login_at && (
                        <p className="text-body text-weak">
                            {t('portal.settings.last_login')}{' '}
                            <DateText value={account.last_login_at} time />
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Form {...logout.form()}>
                            <Button type="submit" variant="outline">
                                {t('portal.sign_out')}
                            </Button>
                        </Form>
                        <Form {...everywhere.form()}>
                            <Button type="submit" variant="destructive">
                                {t('portal.settings.sign_out_everywhere')}
                            </Button>
                        </Form>
                    </div>
                    <p className="text-caption text-weak">
                        {t('portal.settings.everywhere_note')}
                    </p>
                </Card>
            </Section>
        </>
    );
}

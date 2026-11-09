import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import IntegrationsController from '@/actions/App/Http/Controllers/Notifications/IntegrationsController';
import { InboxSettingsSection } from '@/components/inbox/inbox-settings-section';
import { SelectField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { edit } from '@/routes/integrations';
import type { InboxSettingsProps } from '@/types/inbox';
import { home } from '@/routes/settings';

type Smtp = {
    host: string;
    port: number;
    encryption: 'tls' | 'ssl';
    username: string;
    /** The password itself is never sent; only whether one is saved. */
    has_password: boolean;
    from_address: string;
    from_name: string;
    enabled: boolean;
    configured: boolean;
    tested_at: string | null;
    test_ok: boolean | null;
};

type Whatsapp = {
    enabled: boolean;
    events: string[];
    allEvents: string[];
    driver: string;
    configured: boolean;
};

type Props = {
    smtp: Smtp;
    whatsapp: Whatsapp;
    whatsappInbox: InboxSettingsProps;
    branches: { id: number; name: string }[];
    smtpPorts: number[];
    sms: { configured: boolean; driver: 'log' | null };
    fallbackMailer: string;
};

function SmtpStatus({ smtp }: { smtp: Smtp }) {
    const t = useT();

    if (smtp.enabled) {
        return (
            <Badge variant="success">
                {t('integrations.smtp.status.enabled')}
            </Badge>
        );
    }
    if (!smtp.configured) {
        return (
            <Badge variant="neutral">
                {t('integrations.smtp.status.none')}
            </Badge>
        );
    }
    if (smtp.test_ok === true) {
        return (
            <Badge variant="info">{t('integrations.smtp.status.ready')}</Badge>
        );
    }
    if (smtp.test_ok === false) {
        return (
            <Badge variant="danger">
                {t('integrations.smtp.status.failed')}
            </Badge>
        );
    }

    return (
        <Badge variant="warning">
            {t('integrations.smtp.status.untested')}
        </Badge>
    );
}

export default function SettingsIntegrations({
    smtp,
    smtpPorts,
    sms,
    whatsapp,
    whatsappInbox,
    branches,
    fallbackMailer,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [busy, setBusy] = useState<string | null>(null);

    const [waEnabled, setWaEnabled] = useState(whatsapp.enabled);
    const [waEvents, setWaEvents] = useState<string[]>(whatsapp.events);
    const toggleEvent = (event: string) =>
        setWaEvents((current) =>
            current.includes(event)
                ? current.filter((e) => e !== event)
                : [...current, event],
        );
    const saveWhatsapp = () => {
        setBusy('whatsapp');
        router.put(
            IntegrationsController.updateWhatsapp.url(),
            { enabled: waEnabled, events: waEvents },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const post = (key: string, url: string) => {
        setBusy(key);
        router.post(
            url,
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <>
            <Head title={t('integrations.title')} />

            <PageHeader
                title={t('integrations.title')}
                description={t('integrations.description')}
            />

            <div className="max-w-2xl space-y-10">
                <section className="space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-subtitle text-strong">
                                {t('integrations.smtp.title')}
                            </h2>
                            <p className="text-body text-weak">
                                {t('integrations.smtp.description')}
                            </p>
                        </div>
                        <SmtpStatus smtp={smtp} />
                    </div>

                    {!smtp.enabled && (
                        <p className="text-body text-weak">
                            {t('integrations.smtp.fallback', {
                                mailer: fallbackMailer,
                            })}
                        </p>
                    )}

                    <Form
                        {...IntegrationsController.updateSmtp.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess={['password']}
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <TextField
                                        label={t('integrations.smtp.host')}
                                        hint={t('integrations.smtp.host_hint')}
                                        name="host"
                                        dir="ltr"
                                        defaultValue={smtp.host}
                                        autoComplete="off"
                                        required
                                        error={errors.host}
                                    />
                                    <SelectField
                                        label={t('integrations.smtp.port')}
                                        name="port"
                                        defaultValue={String(smtp.port)}
                                        required
                                        options={smtpPorts.map((port) => ({
                                            value: String(port),
                                            label: String(port),
                                        }))}
                                        error={errors.port}
                                    />
                                    <SelectField
                                        label={t(
                                            'integrations.smtp.encryption',
                                        )}
                                        name="encryption"
                                        defaultValue={smtp.encryption}
                                        required
                                        options={[
                                            {
                                                value: 'tls',
                                                label: t(
                                                    'integrations.smtp.encryption_tls',
                                                ),
                                            },
                                            {
                                                value: 'ssl',
                                                label: t(
                                                    'integrations.smtp.encryption_ssl',
                                                ),
                                            },
                                        ]}
                                        error={errors.encryption}
                                    />
                                    <TextField
                                        label={t('integrations.smtp.username')}
                                        name="username"
                                        dir="ltr"
                                        defaultValue={smtp.username}
                                        autoComplete="off"
                                        optional
                                        error={errors.username}
                                    />
                                    <TextField
                                        label={t('integrations.smtp.password')}
                                        hint={
                                            smtp.has_password
                                                ? t(
                                                      'integrations.smtp.password_saved',
                                                  )
                                                : undefined
                                        }
                                        name="password"
                                        type="password"
                                        dir="ltr"
                                        autoComplete="new-password"
                                        optional
                                        error={errors.password}
                                    />
                                    <TextField
                                        label={t(
                                            'integrations.smtp.from_address',
                                        )}
                                        name="from_address"
                                        type="email"
                                        dir="ltr"
                                        inputMode="email"
                                        defaultValue={smtp.from_address}
                                        autoComplete="off"
                                        required
                                        error={errors.from_address}
                                    />
                                    <TextField
                                        label={t('integrations.smtp.from_name')}
                                        name="from_name"
                                        defaultValue={smtp.from_name}
                                        autoComplete="off"
                                        optional
                                        error={errors.from_name}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    loading={processing}
                                    data-test="save-smtp"
                                >
                                    {processing && <Spinner />}
                                    {t('integrations.smtp.save')}
                                </Button>
                            </>
                        )}
                    </Form>

                    {smtp.configured && (
                        <div className="space-y-3 border-t border-stroke-weak pt-6">
                            <p className="text-body text-weak">
                                {t('integrations.smtp.test_hint')}
                                {smtp.tested_at && (
                                    <>
                                        {' '}
                                        {formatDateTime(smtp.tested_at, locale)}
                                    </>
                                )}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    loading={busy === 'test'}
                                    onClick={() =>
                                        post(
                                            'test',
                                            IntegrationsController.testSmtp.url(),
                                        )
                                    }
                                    data-test="test-smtp"
                                >
                                    {busy === 'test' && <Spinner />}
                                    {t('integrations.smtp.test')}
                                </Button>
                                {smtp.enabled ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        loading={busy === 'disable'}
                                        onClick={() =>
                                            post(
                                                'disable',
                                                IntegrationsController.disableSmtp.url(),
                                            )
                                        }
                                    >
                                        {t('integrations.smtp.disable')}
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        disabled={smtp.test_ok !== true}
                                        loading={busy === 'enable'}
                                        onClick={() =>
                                            post(
                                                'enable',
                                                IntegrationsController.enableSmtp.url(),
                                            )
                                        }
                                    >
                                        {t('integrations.smtp.enable')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </section>

                <section
                    className="space-y-4 border-t border-stroke-weak pt-8"
                    data-test="whatsapp-section"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-subtitle text-strong">
                                {t('integrations.whatsapp.title')}
                            </h2>
                            <p className="text-body text-weak">
                                {t('integrations.whatsapp.description')}
                            </p>
                        </div>
                        <Badge
                            variant={whatsapp.configured ? 'info' : 'warning'}
                        >
                            {whatsapp.configured
                                ? t('integrations.whatsapp.status.configured')
                                : t(
                                      'integrations.whatsapp.status.not_configured',
                                  )}
                        </Badge>
                    </div>
                    <p className="text-caption text-weak" dir="ltr">
                        {t('integrations.whatsapp.driver', {
                            driver: whatsapp.driver,
                        })}
                    </p>
                    <label className="flex items-center gap-2 text-body text-strong">
                        <input
                            type="checkbox"
                            checked={waEnabled}
                            onChange={(e) => setWaEnabled(e.target.checked)}
                            data-test="whatsapp-enabled"
                        />
                        {t('integrations.whatsapp.enable')}
                    </label>
                    <fieldset
                        className="grid gap-2 sm:grid-cols-2"
                        disabled={!waEnabled}
                    >
                        <legend className="mb-1 text-body font-semibold text-strong">
                            {t('integrations.whatsapp.events')}
                        </legend>
                        {whatsapp.allEvents.map((event) => (
                            <label
                                key={event}
                                className="flex items-center gap-2 text-body"
                            >
                                <input
                                    type="checkbox"
                                    checked={waEvents.includes(event)}
                                    onChange={() => toggleEvent(event)}
                                />
                                {t(
                                    `whatsapp.event_name.${event}` as TranslationKey,
                                )}
                            </label>
                        ))}
                    </fieldset>
                    <Button
                        type="button"
                        loading={busy === 'whatsapp'}
                        onClick={saveWhatsapp}
                        data-test="save-whatsapp"
                    >
                        {busy === 'whatsapp' && <Spinner />}
                        {t('integrations.whatsapp.save')}
                    </Button>
                </section>

                <InboxSettingsSection
                    inbox={whatsappInbox}
                    branches={branches}
                />

                <section className="space-y-3 border-t border-stroke-weak pt-8">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-subtitle text-strong">
                                {t('integrations.sms.title')}
                            </h2>
                            <p className="text-body text-weak">
                                {t('integrations.sms.description')}
                            </p>
                        </div>
                        <Badge variant={sms.configured ? 'info' : 'neutral'}>
                            {sms.configured
                                ? t('integrations.sms.dev_log')
                                : t('integrations.sms.not_configured')}
                        </Badge>
                    </div>
                    <p className="text-body text-weak">
                        {sms.configured
                            ? t('integrations.sms.dev_log_note')
                            : t('integrations.sms.not_configured_note')}
                    </p>
                    <p className="text-caption text-weak">
                        {t('integrations.sms.whatsapp_note')}
                    </p>
                </section>
            </div>
        </>
    );
}

SettingsIntegrations.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'integrations.title', href: edit() },
    ],
};

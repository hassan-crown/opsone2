import { Head, useForm } from '@inertiajs/react';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { StoreNav } from '@/components/store/store-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type Props = {
    settings: {
        enabled: boolean;
        branch_id: number | null;
        assignee_id: number | null;
        reservation_minutes: number;
        link_hours_default: number;
        link_days_max: number;
        lock_version: number;
    };
    branches: { id: number; name: string }[];
    members: { id: number; name: string }[];
    licensed: boolean;
    open: boolean;
    payments: {
        mode: 'test';
        publishable_key: string;
        has_secret_key: boolean;
        has_webhook_secret: boolean;
        enabled: boolean;
        configured: boolean;
        from_environment: boolean;
        webhook_url: string;
    };
};

/** StoreSettings (P-02): the store, its reservation window and basket-link validity, and the Moyasar test account. */
export default function StoreSettings({
    settings,
    branches,
    members,
    licensed,
    open,
    payments,
}: Props) {
    const t = useT();
    const form = useForm({
        enabled: settings.enabled,
        branch_id:
            settings.branch_id === null ? '' : String(settings.branch_id),
        assignee_id:
            settings.assignee_id === null ? '' : String(settings.assignee_id),
        reservation_minutes: String(settings.reservation_minutes),
        link_hours_default: String(settings.link_hours_default),
        link_days_max: String(settings.link_days_max),
        lock_version: settings.lock_version,
    });
    const pay = useForm({
        publishable_key: payments.publishable_key,
        secret_key: '',
        webhook_secret: '',
        enabled: payments.enabled || !payments.from_environment,
    });

    return (
        <>
            <Head title={t('store.settings.title')} />
            <PageHeader
                title={t('store.settings.title')}
                description={t('store.settings.description')}
                actions={
                    <Badge variant={open ? 'success' : 'neutral'}>
                        {open
                            ? t('store.settings.open')
                            : t('store.settings.closed')}
                    </Badge>
                }
            />
            <StoreNav active="settings" />
            {!licensed && (
                <p
                    role="alert"
                    className="mb-6 rounded-md bg-warning-bg px-4 py-3 text-warning-text"
                >
                    {t('store.settings.unlicensed')}
                </p>
            )}

            <form
                className="mb-8 grid max-w-2xl gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/store-admin/settings', { preserveScroll: true });
                }}
            >
                <h2 className="text-subtitle">{t('store.settings.store')}</h2>
                <CheckboxField
                    label={t('store.settings.enabled')}
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                    error={form.errors.enabled}
                />
                <SelectField
                    label={t('store.settings.branch')}
                    hint={t('store.settings.branch_hint')}
                    value={form.data.branch_id}
                    placeholder={t('store.settings.choose')}
                    options={branches.map((b) => ({
                        value: String(b.id),
                        label: b.name,
                    }))}
                    onChange={(e) => form.setData('branch_id', e.target.value)}
                    error={form.errors.branch_id}
                />
                <SelectField
                    label={t('store.settings.assignee')}
                    hint={t('store.settings.assignee_hint')}
                    value={form.data.assignee_id}
                    placeholder={t('store.settings.choose')}
                    options={members.map((m) => ({
                        value: String(m.id),
                        label: m.name,
                    }))}
                    onChange={(e) =>
                        form.setData('assignee_id', e.target.value)
                    }
                    error={form.errors.assignee_id}
                />
                <div className="grid gap-4 sm:grid-cols-3">
                    <TextField
                        label={t('store.settings.reservation_minutes')}
                        type="number"
                        min={5}
                        max={120}
                        value={form.data.reservation_minutes}
                        onChange={(e) =>
                            form.setData('reservation_minutes', e.target.value)
                        }
                        error={form.errors.reservation_minutes}
                    />
                    <TextField
                        label={t('store.settings.link_hours_default')}
                        type="number"
                        min={1}
                        max={168}
                        value={form.data.link_hours_default}
                        onChange={(e) =>
                            form.setData('link_hours_default', e.target.value)
                        }
                        error={form.errors.link_hours_default}
                    />
                    <TextField
                        label={t('store.settings.link_days_max')}
                        type="number"
                        min={1}
                        max={7}
                        value={form.data.link_days_max}
                        onChange={(e) =>
                            form.setData('link_days_max', e.target.value)
                        }
                        error={form.errors.link_days_max}
                    />
                </div>
                <div>
                    <Button type="submit" disabled={form.processing}>
                        {t('store.settings.save')}
                    </Button>
                </div>
            </form>

            <form
                className="grid max-w-2xl gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    pay.put('/store-admin/settings/payments', {
                        preserveScroll: true,
                        onSuccess: () =>
                            pay.reset('secret_key', 'webhook_secret'),
                    });
                }}
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-subtitle">
                        {t('store.payments.title')}
                    </h2>
                    <Badge variant="warning">
                        {t('store.payments.test_mode')}
                    </Badge>
                </div>
                <p className="text-caption text-weak">
                    {t('store.payments.description')}
                </p>
                {payments.from_environment && (
                    <p className="text-caption text-weak">
                        {t('store.payments.from_environment')}
                    </p>
                )}
                <TextField
                    label={t('store.payments.publishable_key')}
                    dir="ltr"
                    autoComplete="off"
                    value={pay.data.publishable_key}
                    onChange={(e) =>
                        pay.setData('publishable_key', e.target.value)
                    }
                    error={pay.errors.publishable_key}
                    required
                />
                <TextField
                    label={t('store.payments.secret_key')}
                    hint={
                        payments.has_secret_key
                            ? t('store.payments.keep_saved')
                            : undefined
                    }
                    type="password"
                    dir="ltr"
                    autoComplete="new-password"
                    value={pay.data.secret_key}
                    onChange={(e) => pay.setData('secret_key', e.target.value)}
                    error={pay.errors.secret_key}
                />
                <TextField
                    label={t('store.payments.webhook_secret')}
                    hint={
                        payments.has_webhook_secret
                            ? t('store.payments.keep_saved')
                            : t('store.payments.webhook_secret_hint')
                    }
                    type="password"
                    dir="ltr"
                    autoComplete="new-password"
                    value={pay.data.webhook_secret}
                    onChange={(e) =>
                        pay.setData('webhook_secret', e.target.value)
                    }
                    error={pay.errors.webhook_secret}
                />
                <div className="grid gap-1">
                    <span className="text-label font-medium">
                        {t('store.payments.webhook_url')}
                    </span>
                    <code
                        dir="ltr"
                        className="rounded-md bg-fill px-3 py-2 text-start text-caption break-all"
                    >
                        {payments.webhook_url}
                    </code>
                </div>
                <CheckboxField
                    label={t('store.payments.enabled')}
                    checked={pay.data.enabled}
                    onChange={(e) => pay.setData('enabled', e.target.checked)}
                />
                <div>
                    <Button type="submit" disabled={pay.processing}>
                        {t('store.payments.save')}
                    </Button>
                </div>
            </form>
        </>
    );
}

import { Head, router, useForm } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { SiteNav } from '@/components/site/shared';
import { TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { SiteTabs } from '@/types/site';

type Props = {
    domain: {
        hostname: string;
        status: 'pending' | 'verified' | 'failed';
        txt_name: string;
        txt_value: string;
        verified_at: string | null;
        last_checked_at: string | null;
        last_error: string | null;
    } | null;
    platformHost: string | null;
    tabs: SiteTabs;
};

const VARIANT = {
    pending: 'warning',
    verified: 'success',
    failed: 'danger',
} as const;

export default function DomainVerify({ domain, platformHost, tabs }: Props) {
    const t = useT();
    const form = useForm({ hostname: '' });

    return (
        <>
            <Head title={t('site.domain.title')} />
            <PageHeader
                title={t('site.domain.title')}
                description={t('site.domain.description')}
            />
            <SiteNav active="domain" tabs={tabs} />

            <section className="mb-6 grid gap-2 rounded-lg border border-stroke-weak bg-surface p-4">
                <h2 className="text-subtitle">{t('site.domain.default')}</h2>
                <p dir="ltr" className="text-start text-body">
                    {platformHost}
                </p>
                <p className="text-caption text-weak">
                    {t('site.domain.default_hint')}
                </p>
            </section>

            {domain === null ? (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/site-admin/domain');
                    }}
                    className="grid max-w-xl gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                >
                    <h2 className="text-subtitle">{t('site.domain.custom')}</h2>
                    <TextField
                        label={t('site.domain.hostname')}
                        hint={t('site.domain.hostname_hint')}
                        dir="ltr"
                        placeholder="www.example.com"
                        value={form.data.hostname}
                        onChange={(e) =>
                            form.setData('hostname', e.target.value)
                        }
                        error={form.errors.hostname}
                        required
                    />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {t('site.domain.request')}
                        </Button>
                    </div>
                </form>
            ) : (
                <section
                    className="grid gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                    aria-labelledby="dom-h"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="dom-h" className="text-subtitle" dir="ltr">
                            {domain.hostname}
                        </h2>
                        <Badge variant={VARIANT[domain.status]}>
                            {t(
                                `site.domain.status.${domain.status}` as TranslationKey,
                            )}
                        </Badge>
                    </div>
                    {domain.status !== 'verified' && (
                        <div className="grid gap-2">
                            <p className="text-body">
                                {t('site.domain.instructions')}
                            </p>
                            <dl
                                className="grid gap-1 rounded-md bg-fill p-3 text-caption"
                                dir="ltr"
                            >
                                <div className="flex gap-2">
                                    <dt className="font-medium">Type</dt>
                                    <dd>TXT</dd>
                                </div>
                                <div className="flex gap-2">
                                    <dt className="font-medium">Name</dt>
                                    <dd className="break-all">
                                        {domain.txt_name}
                                    </dd>
                                </div>
                                <div className="flex gap-2">
                                    <dt className="font-medium">Value</dt>
                                    <dd className="break-all">
                                        {domain.txt_value}
                                    </dd>
                                </div>
                            </dl>
                            {domain.last_error && (
                                <p role="alert" className="text-danger-text">
                                    {t(
                                        `site.domain.error_${domain.last_error}` as TranslationKey,
                                    )}
                                </p>
                            )}
                        </div>
                    )}
                    {domain.status === 'verified' && (
                        <p className="text-body">
                            {t('site.domain.verified_hint')}
                        </p>
                    )}
                    <p className="text-caption text-weak">
                        {t('site.domain.server_note')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            onClick={() =>
                                router.post(
                                    '/site-admin/domain/check',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            data-test="domain-check"
                        >
                            <Icon icon={RefreshCw} />
                            {t('site.domain.check')}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.delete('/site-admin/domain', {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Icon icon={Trash2} />
                            {t('site.domain.remove')}
                        </Button>
                    </div>
                </section>
            )}
        </>
    );
}

import { Head, Link, useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { SiteNav } from '@/components/site/shared';
import { TextField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { SiteTabs } from '@/types/site';

const TAGS = [
    ['gtm_id', 'GTM-XXXXXXX'],
    ['ga4_id', 'G-XXXXXXXXXX'],
    ['meta_pixel_id', '123456789012345'],
    ['tiktok_pixel_id', 'CXXXXXXXXXXXXXXXXXXX'],
    ['snap_pixel_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'],
    ['google_ads_id', 'AW-123456789'],
    ['google_ads_purchase_label', 'AbCdEf123'],
    ['google_ads_lead_label', 'AbCdEf456'],
] as const;

type TagKey = (typeof TAGS)[number][0];

type Props = {
    settings: Record<TagKey, string | null> & {
        whatsapp_number: string | null;
        whatsapp_message: { ar: string | null; en: string | null };
        links: Record<string, string>;
        lock_version: number | null;
    };
    linkKeys: string[];
    privacy: { published: boolean; edit_url: string | null };
    indexing: boolean;
    tabs: SiteTabs;
};

/** SiteMarketing (ADR-0045, P-107): tag IDs only, the WhatsApp button and the facility's public links. */
export default function SiteMarketing({
    settings,
    linkKeys,
    privacy,
    tabs,
}: Props) {
    const t = useT();
    const tagValues = Object.fromEntries(
        TAGS.map(([key]) => [key, settings[key] ?? '']),
    ) as Record<TagKey, string>;
    const form = useForm({
        ...tagValues,
        whatsapp_number: settings.whatsapp_number ?? '',
        whatsapp_message: {
            ar: settings.whatsapp_message.ar ?? '',
            en: settings.whatsapp_message.en ?? '',
        },
        links: Object.fromEntries(
            linkKeys.map((key) => [key, settings.links[key] ?? '']),
        ),
        lock_version: settings.lock_version,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title={t('marketing.title')} />
            <PageHeader
                title={t('marketing.title')}
                description={t('marketing.description')}
            />
            <SiteNav active="marketing" tabs={tabs} />

            {!privacy.published && (
                <Alert variant="warning" className="mb-4">
                    <Icon icon={ShieldAlert} />
                    <AlertBody>
                        <AlertDescription>
                            {t('marketing.privacy_unpublished')}{' '}
                            {privacy.edit_url && (
                                <Link
                                    href={privacy.edit_url}
                                    className="text-link underline"
                                >
                                    {t('marketing.privacy_review')}
                                </Link>
                            )}
                        </AlertDescription>
                    </AlertBody>
                </Alert>
            )}

            <form
                className="grid gap-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/site-admin/marketing', { preserveScroll: true });
                }}
            >
                <Card className="gap-4">
                    <div>
                        <h2 className="text-heading text-strong">
                            {t('marketing.tags_heading')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('marketing.tags_hint')}
                        </p>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {TAGS.map(([key, placeholder]) => (
                            <TextField
                                key={key}
                                label={t(
                                    `marketing.field.${key}` as TranslationKey,
                                )}
                                dir="ltr"
                                placeholder={placeholder}
                                value={form.data[key]}
                                onChange={(e) =>
                                    form.setData(key, e.target.value)
                                }
                                error={errors[key]}
                                optional
                            />
                        ))}
                    </div>
                </Card>

                <Card className="gap-4">
                    <div>
                        <h2 className="text-heading text-strong">
                            {t('marketing.whatsapp_heading')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('marketing.whatsapp_hint')}
                        </p>
                    </div>
                    <TextField
                        label={t('marketing.field.whatsapp_number')}
                        dir="ltr"
                        inputMode="tel"
                        placeholder="+9665XXXXXXXX"
                        value={form.data.whatsapp_number}
                        onChange={(e) =>
                            form.setData('whatsapp_number', e.target.value)
                        }
                        error={errors.whatsapp_number}
                        optional
                    />
                    <div className="grid gap-4 md:grid-cols-2">
                        {(['ar', 'en'] as const).map((lang) => (
                            <TextareaField
                                key={lang}
                                label={`${t('marketing.field.whatsapp_message')} — ${t(`marketing.lang_${lang}` as TranslationKey)}`}
                                lang={lang}
                                dir={lang === 'ar' ? 'rtl' : 'ltr'}
                                rows={2}
                                value={form.data.whatsapp_message[lang]}
                                onChange={(e) =>
                                    form.setData('whatsapp_message', {
                                        ...form.data.whatsapp_message,
                                        [lang]: e.target.value,
                                    })
                                }
                                error={errors[`whatsapp_message.${lang}`]}
                                optional
                            />
                        ))}
                    </div>
                </Card>

                <Card className="gap-4">
                    <div>
                        <h2 className="text-heading text-strong">
                            {t('marketing.links_heading')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('marketing.links_hint')}
                        </p>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {linkKeys.map((key) => (
                            <TextField
                                key={key}
                                label={t(
                                    `marketing.link.${key}` as TranslationKey,
                                )}
                                dir="ltr"
                                type="url"
                                placeholder="https://"
                                value={form.data.links[key] ?? ''}
                                onChange={(e) =>
                                    form.setData('links', {
                                        ...form.data.links,
                                        [key]: e.target.value,
                                    })
                                }
                                error={errors[`links.${key}`]}
                                optional
                            />
                        ))}
                    </div>
                </Card>

                {errors.lock_version && (
                    <p role="alert" className="text-danger-text">
                        {errors.lock_version}
                    </p>
                )}
                <div>
                    <Button type="submit" disabled={form.processing}>
                        {t('marketing.save')}
                    </Button>
                </div>
            </form>
        </>
    );
}

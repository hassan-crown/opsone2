import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye } from 'lucide-react';
import { useEffect, useState } from 'react';
import PurchaseDocumentController from '@/actions/App/Http/Controllers/Procurement/PurchaseDocumentController';
import {
    CheckboxField,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { FormErrors, ProcurementTabs } from '@/components/procurement/shared';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Localized = { ar: string | null; en: string | null };

type Layout = {
    blocks: { key: string; visible: boolean }[];
    flags: Record<string, boolean>;
    title: Localized;
    terms: Localized;
};

type Email = {
    subject: { ar: string; en: string };
    body: { ar: string; en: string };
};

type Props = {
    version: number;
    layout: Layout;
    email: Email;
    options: { blocks: string[]; flags: string[]; placeholders: string[] };
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The facility's purchase-order document (ADR-0038): which blocks appear and in what order, what the workshop may
 * see of the customer, line details, terms, signature and stamp, and the email's texts. Saving makes a new version
 * that every generated PDF records; the preview renders the unsaved layout.
 */
export default function PurchaseDocumentSettings({
    version,
    layout: saved,
    email: savedEmail,
    options,
}: Props) {
    const t = useT();
    const [layout, setLayout] = useState<Layout>(saved);
    const [email, setEmail] = useState<Email>(savedEmail);
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [saving, setSaving] = useState(false);
    const [locale, setLocale] = useState<'ar' | 'en'>('ar');
    const [preview, setPreview] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [previewError, setPreviewError] = useState(false);

    useEffect(
        () => () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        },
        [preview],
    );

    const move = (index: number, delta: number) => {
        const next = [...layout.blocks];
        const target = index + delta;
        if (target < 0 || target >= next.length) {
            return;
        }
        [next[index], next[target]] = [next[target], next[index]];
        setLayout({ ...layout, blocks: next });
    };

    const save = () => {
        setSaving(true);
        router.put(
            PurchaseDocumentController.save.url(),
            { version, layout, email },
            {
                preserveScroll: true,
                onStart: () => setErrors({}),
                onError: (e) => setErrors(e),
                onFinish: () => setSaving(false),
            },
        );
    };

    const renderPreview = async () => {
        setPreviewing(true);
        setPreviewError(false);
        try {
            const response = await fetch(
                PurchaseDocumentController.preview.url(),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/pdf',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({ layout, locale }),
                },
            );
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            setPreview(URL.createObjectURL(await response.blob()));
        } catch {
            setPreviewError(true);
        } finally {
            setPreviewing(false);
        }
    };

    const text = (value: string | null) => value ?? '';

    return (
        <>
            <Head title={t('purchase_document.settings_title')} />
            <PageHeader
                title={t('purchase_document.settings_title')}
                description={t('purchase_document.settings_desc', {
                    version,
                })}
            />
            <ProcurementTabs active="suppliers" />
            <div className="grid gap-4 xl:grid-cols-2">
                <div className="grid content-start gap-4">
                    <FormErrors errors={errors} />
                    <Card className="gap-3" data-test="document-blocks">
                        <h2 className="text-heading text-strong">
                            {t('purchase_document.blocks_title')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('purchase_document.blocks_hint')}
                        </p>
                        <ol className="grid gap-1">
                            {layout.blocks.map((block, i) => (
                                <li
                                    key={block.key}
                                    className="flex items-center gap-2 rounded-md border border-stroke-weak px-3"
                                >
                                    <CheckboxField
                                        className="flex-1"
                                        label={t(
                                            `purchase_document.block.${block.key}` as TranslationKey,
                                        )}
                                        checked={block.visible}
                                        onChange={(e) =>
                                            setLayout({
                                                ...layout,
                                                blocks: layout.blocks.map(
                                                    (b, j) =>
                                                        j === i
                                                            ? {
                                                                  ...b,
                                                                  visible:
                                                                      e.target
                                                                          .checked,
                                                              }
                                                            : b,
                                                ),
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={t(
                                            'purchase_document.move_up',
                                        )}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                    >
                                        <Icon icon={ArrowUp} />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={t(
                                            'purchase_document.move_down',
                                        )}
                                        disabled={
                                            i === layout.blocks.length - 1
                                        }
                                        onClick={() => move(i, 1)}
                                    >
                                        <Icon icon={ArrowDown} />
                                    </Button>
                                </li>
                            ))}
                        </ol>
                    </Card>
                    <Card className="gap-1" data-test="document-flags">
                        <h2 className="text-heading text-strong">
                            {t('purchase_document.flags_title')}
                        </h2>
                        {options.flags.map((flag) => (
                            <CheckboxField
                                key={flag}
                                label={t(
                                    `purchase_document.flag.${flag}` as TranslationKey,
                                )}
                                checked={layout.flags[flag] ?? false}
                                onChange={(e) =>
                                    setLayout({
                                        ...layout,
                                        flags: {
                                            ...layout.flags,
                                            [flag]: e.target.checked,
                                        },
                                    })
                                }
                            />
                        ))}
                    </Card>
                    <Card className="gap-3">
                        <h2 className="text-heading text-strong">
                            {t('purchase_document.texts_title')}
                        </h2>
                        <div className="grid gap-3 md:grid-cols-2">
                            <TextField
                                label={t('purchase_document.title_ar')}
                                value={text(layout.title.ar)}
                                onChange={(e) =>
                                    setLayout({
                                        ...layout,
                                        title: {
                                            ...layout.title,
                                            ar: e.target.value,
                                        },
                                    })
                                }
                                error={errors['layout.title.ar']}
                                required
                            />
                            <TextField
                                label={t('purchase_document.title_en')}
                                dir="ltr"
                                value={text(layout.title.en)}
                                onChange={(e) =>
                                    setLayout({
                                        ...layout,
                                        title: {
                                            ...layout.title,
                                            en: e.target.value,
                                        },
                                    })
                                }
                                optional
                            />
                        </div>
                        <TextareaField
                            label={t('purchase_document.terms_ar')}
                            value={text(layout.terms.ar)}
                            onChange={(e) =>
                                setLayout({
                                    ...layout,
                                    terms: {
                                        ...layout.terms,
                                        ar: e.target.value,
                                    },
                                })
                            }
                            optional
                            error={errors['layout.terms.ar']}
                        />
                        <TextareaField
                            label={t('purchase_document.terms_en')}
                            dir="ltr"
                            value={text(layout.terms.en)}
                            onChange={(e) =>
                                setLayout({
                                    ...layout,
                                    terms: {
                                        ...layout.terms,
                                        en: e.target.value,
                                    },
                                })
                            }
                            optional
                        />
                    </Card>
                    <Card className="gap-3" data-test="document-email">
                        <h2 className="text-heading text-strong">
                            {t('purchase_document.email_texts_title')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('purchase_document.placeholders_hint', {
                                list: options.placeholders
                                    .map((p) => `{${p}}`)
                                    .join(' '),
                            })}
                        </p>
                        {(['ar', 'en'] as const).map((lang) => (
                            <div key={lang} className="grid gap-3">
                                <TextField
                                    label={t(
                                        `purchase_document.subject_${lang}` as TranslationKey,
                                    )}
                                    dir={lang === 'en' ? 'ltr' : undefined}
                                    value={email.subject[lang]}
                                    onChange={(e) =>
                                        setEmail({
                                            ...email,
                                            subject: {
                                                ...email.subject,
                                                [lang]: e.target.value,
                                            },
                                        })
                                    }
                                    required={lang === 'ar'}
                                    optional={lang === 'en'}
                                    error={errors[`email.subject.${lang}`]}
                                />
                                <TextareaField
                                    label={t(
                                        `purchase_document.body_${lang}` as TranslationKey,
                                    )}
                                    dir={lang === 'en' ? 'ltr' : undefined}
                                    value={email.body[lang]}
                                    onChange={(e) =>
                                        setEmail({
                                            ...email,
                                            body: {
                                                ...email.body,
                                                [lang]: e.target.value,
                                            },
                                        })
                                    }
                                    required={lang === 'ar'}
                                    optional={lang === 'en'}
                                    rows={5}
                                    error={errors[`email.body.${lang}`]}
                                />
                            </div>
                        ))}
                    </Card>
                    <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                        <Button
                            type="button"
                            loading={saving}
                            disabled={saving}
                            onClick={save}
                            data-test="save-document"
                        >
                            {t('purchase_document.save')}
                        </Button>
                    </div>
                </div>
                <Card className="content-start gap-3 xl:sticky xl:top-4 xl:self-start">
                    <div className="flex flex-wrap items-end gap-2">
                        <SelectField
                            label={t('purchase_document.language')}
                            value={locale}
                            onChange={(e) =>
                                setLocale(e.target.value === 'en' ? 'en' : 'ar')
                            }
                            options={[
                                {
                                    value: 'ar',
                                    label: t('purchase_document.language_ar'),
                                },
                                {
                                    value: 'en',
                                    label: t('purchase_document.language_en'),
                                },
                            ]}
                            className="w-40"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            loading={previewing}
                            disabled={previewing}
                            onClick={renderPreview}
                            data-test="preview-document"
                        >
                            <Icon icon={Eye} />
                            {t('purchase_document.preview')}
                        </Button>
                    </div>
                    {previewError && (
                        <Alert variant="danger">
                            <AlertBody>
                                {t('purchase_document.preview_failed')}
                            </AlertBody>
                        </Alert>
                    )}
                    {preview ? (
                        <iframe
                            title={t('purchase_document.preview')}
                            src={preview}
                            className="h-[75vh] w-full rounded-md border border-stroke-weak"
                        />
                    ) : (
                        <p className="text-caption text-weak">
                            {t('purchase_document.preview_hint')}
                        </p>
                    )}
                </Card>
            </div>
        </>
    );
}

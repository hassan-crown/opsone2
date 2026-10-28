import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    Eye,
    ImagePlus,
    Rocket,
    RotateCcw,
    Save,
    Undo2,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { SectionEditor, emptyData } from '@/components/site/section-fields';
import { PageStateBadge, SiteNav } from '@/components/site/shared';
import {
    CheckboxField,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    LocaleContent,
    PageState,
    Readiness,
    Section,
    SectionType,
    SiteImageFile,
    SiteTabs,
} from '@/types/site';

type Lang = 'ar' | 'en';
type Props = {
    page: {
        id: number;
        slug: string;
        policy: string;
        state: PageState;
        show_in_nav: boolean;
        is_home: boolean;
    };
    content: Record<Lang, LocaleContent>;
    readiness: Readiness;
    images: SiteImageFile[];
    sectionTypes: SectionType[];
    links: string[];
    can: {
        edit: boolean;
        publish: boolean;
        unpublish: boolean;
        archive: boolean;
        restore: boolean;
    };
    tabs: SiteTabs;
};

const post = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, data as never, { preserveScroll: true });

export default function PageEditor({
    page,
    content,
    readiness,
    images,
    sectionTypes,
    links,
    can,
    tabs,
}: Props) {
    const t = useT();
    const errors = usePage().props.errors as Record<string, string>;
    const initial = {
        slug: page.slug,
        show_in_nav: page.show_in_nav,
        content: {
            ar: {
                title: content.ar.title,
                meta_description: content.ar.meta_description,
                sections: content.ar.sections,
            },
            en: {
                title: content.en.title,
                meta_description: content.en.meta_description,
                sections: content.en.sections,
            },
        },
    };
    const [data, setData] = useState(initial);
    const [processing, setProcessing] = useState(false);
    const form = {
        data,
        processing,
        isDirty: JSON.stringify(data) !== JSON.stringify(initial),
        setData: <K extends keyof typeof initial>(
            key: K,
            value: (typeof initial)[K],
        ) => setData((current) => ({ ...current, [key]: value })),
        put: (url: string, options: { preserveScroll: boolean }) =>
            router.put(url, data as never, {
                ...options,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            }),
    };
    const [lang, setLang] = useState<Lang>('ar');
    const [publishing, setPublishing] = useState(false);
    const [chosen, setChosen] = useState<Lang[]>(['ar', 'en']);
    const [adding, setAdding] = useState<SectionType>(sectionTypes[0]);
    const file = useRef<HTMLInputElement>(null);

    const c = form.data.content[lang];
    const setC = (patch: Partial<typeof c>) =>
        form.setData('content', {
            ...form.data.content,
            [lang]: { ...c, ...patch },
        });
    const sections = c.sections as Section[];
    const setSections = (next: Section[]) => setC({ sections: next });
    const disabled = !can.edit;
    const errorList = Object.values(errors);
    const dirty = form.isDirty;
    const base = `/site-admin/pages/${page.id}`;

    const move = (i: number, delta: -1 | 1) => {
        const next = [...sections];
        [next[i], next[i + delta]] = [next[i + delta], next[i]];
        setSections(next);
    };

    return (
        <>
            <Head title={t('site.editor.title')} />
            <PageHeader
                title={page.is_home ? t('site.editor.home') : `/${page.slug}`}
                description={t('site.editor.description')}
                actions={
                    <>
                        <PageStateBadge state={page.state} />
                        {can.edit && (
                            <Button variant="outline" asChild>
                                <a
                                    href={`${base}/preview/${lang}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Icon icon={Eye} />
                                    {t('site.pages.preview')}
                                </a>
                            </Button>
                        )}
                        {can.edit && (
                            <Button
                                onClick={() =>
                                    form.put(base, { preserveScroll: true })
                                }
                                disabled={form.processing || !dirty}
                                data-test="save-draft"
                            >
                                <Icon icon={Save} />
                                {t('site.editor.save_draft')}
                            </Button>
                        )}
                        {can.publish && (
                            <Button
                                variant="outline"
                                onClick={() => setPublishing(true)}
                                disabled={dirty}
                                data-test="publish-open"
                            >
                                <Icon icon={Rocket} />
                                {t('site.editor.publish')}
                            </Button>
                        )}
                    </>
                }
            />
            <SiteNav active="pages" tabs={tabs} />

            {errorList.length > 0 && (
                <Alert variant="destructive" className="mb-4" role="alert">
                    <AlertTitle>{t('site.editor.errors')}</AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc ps-5">
                            {errorList.map((e, i) => (
                                <li key={i}>{e}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
            {dirty && can.publish && (
                <p className="mb-4 text-caption text-weak">
                    {t('site.editor.save_first')}
                </p>
            )}

            <div
                className="mb-4 flex flex-wrap items-center gap-2"
                role="group"
                aria-label={t('site.editor.language')}
            >
                {(['ar', 'en'] as const).map((l) => (
                    <Button
                        key={l}
                        type="button"
                        variant={lang === l ? 'default' : 'outline'}
                        aria-pressed={lang === l}
                        onClick={() => setLang(l)}
                    >
                        {l === 'ar'
                            ? t('site.editor.lang_ar')
                            : t('site.editor.lang_en')}
                        {!readiness[l].complete && readiness[l].has_draft && (
                            <span className="text-caption">
                                ({readiness[l].issues.length})
                            </span>
                        )}
                    </Button>
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                <div className="grid gap-4">
                    <div className="grid gap-4 rounded-lg border border-stroke-weak bg-surface p-4">
                        <TextField
                            label={t('site.editor.page_title')}
                            value={c.title}
                            onChange={(e) => setC({ title: e.target.value })}
                            lang={lang}
                            dir={lang === 'ar' ? 'rtl' : 'ltr'}
                            disabled={disabled}
                            required
                        />
                        <TextareaField
                            label={t('site.editor.meta')}
                            hint={t('site.editor.meta_hint')}
                            value={c.meta_description}
                            onChange={(e) =>
                                setC({ meta_description: e.target.value })
                            }
                            lang={lang}
                            dir={lang === 'ar' ? 'rtl' : 'ltr'}
                            disabled={disabled}
                            rows={2}
                        />
                        {page.policy === 'content' && (
                            <div className="grid gap-4 md:grid-cols-2">
                                <TextField
                                    label={t('site.pages.field_slug')}
                                    dir="ltr"
                                    value={form.data.slug}
                                    onChange={(e) =>
                                        form.setData(
                                            'slug',
                                            e.target.value.toLowerCase(),
                                        )
                                    }
                                    disabled={
                                        disabled ||
                                        content.ar.published ||
                                        content.en.published
                                    }
                                    hint={
                                        content.ar.published ||
                                        content.en.published
                                            ? t('site.editor.slug_locked')
                                            : t('site.pages.field_slug_hint')
                                    }
                                />
                                <CheckboxField
                                    label={t('site.pages.field_nav')}
                                    checked={form.data.show_in_nav}
                                    onChange={(e) =>
                                        form.setData(
                                            'show_in_nav',
                                            e.target.checked,
                                        )
                                    }
                                    disabled={disabled}
                                />
                            </div>
                        )}
                    </div>

                    {sections.map((s, i) => (
                        <SectionEditor
                            key={s.id}
                            section={s}
                            index={i}
                            count={sections.length}
                            lang={lang}
                            images={images}
                            links={links}
                            disabled={disabled}
                            onChange={(next) =>
                                setSections(
                                    sections.map((x, j) =>
                                        j === i ? next : x,
                                    ),
                                )
                            }
                            onMove={(d) => move(i, d)}
                            onRemove={() =>
                                setSections(sections.filter((_, j) => j !== i))
                            }
                        />
                    ))}
                    {sections.length === 0 && (
                        <p className="text-body text-weak">
                            {t('site.editor.no_sections')}
                        </p>
                    )}

                    {can.edit && (
                        <div className="flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-stroke p-4">
                            <SelectField
                                label={t('site.editor.add_section')}
                                value={adding}
                                onChange={(e) =>
                                    setAdding(e.target.value as SectionType)
                                }
                                options={sectionTypes.map((type) => ({
                                    value: type,
                                    label: t(
                                        `site.section.${type}` as TranslationKey,
                                    ),
                                }))}
                                className="min-w-56"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    setSections([
                                        ...sections,
                                        {
                                            id: `n${Date.now().toString(36)}`,
                                            type: adding,
                                            visible: true,
                                            data: emptyData(adding),
                                        },
                                    ])
                                }
                                data-test="add-section"
                            >
                                {t('site.editor.add')}
                            </Button>
                        </div>
                    )}
                </div>

                <aside className="grid content-start gap-4">
                    <section
                        className="grid gap-2 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="media-h"
                    >
                        <h2 id="media-h" className="text-subtitle">
                            {t('site.editor.media')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('site.editor.media_hint')}
                        </p>
                        <ul className="grid grid-cols-3 gap-2">
                            {images.map((i) => (
                                <li key={i.uuid}>
                                    <img
                                        src={i.url}
                                        alt={i.name}
                                        className="aspect-square w-full rounded-md border border-stroke-weak object-cover"
                                        loading="lazy"
                                    />
                                </li>
                            ))}
                        </ul>
                        {can.edit && (
                            <>
                                <input
                                    ref={file}
                                    type="file"
                                    accept="image/png,image/webp"
                                    className="sr-only"
                                    aria-label={t('site.editor.upload')}
                                    data-test="image-input"
                                    onChange={(e) => {
                                        const f = e.target.files?.[0];
                                        if (f)
                                            router.post(
                                                `${base}/images`,
                                                { file: f },
                                                {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                },
                                            );
                                        e.target.value = '';
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => file.current?.click()}
                                >
                                    <Icon icon={ImagePlus} />
                                    {t('site.editor.upload')}
                                </Button>
                            </>
                        )}
                    </section>

                    <section
                        className="grid gap-2 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="ready-h"
                    >
                        <h2 id="ready-h" className="text-subtitle">
                            {t('site.editor.readiness')}
                        </h2>
                        {(['ar', 'en'] as const).map((l) => (
                            <div key={l}>
                                <p className="text-label font-medium">
                                    {l === 'ar'
                                        ? t('site.editor.lang_ar')
                                        : t('site.editor.lang_en')}
                                </p>
                                {!readiness[l].has_draft ? (
                                    <p className="text-caption text-weak">
                                        {t('site.editor.no_draft')}
                                    </p>
                                ) : readiness[l].complete ? (
                                    <p className="text-caption text-success-text">
                                        {t('site.editor.complete')}
                                    </p>
                                ) : (
                                    <ul className="list-disc ps-5 text-caption text-warning-text">
                                        {readiness[l].issues.map((issue, i) => (
                                            <li key={i}>
                                                {issue.section === null
                                                    ? ''
                                                    : `${issue.section + 1}: `}
                                                {t(
                                                    `site.issue.${issue.code}` as TranslationKey,
                                                )}
                                                {issue.field
                                                    ? ` (${issue.field})`
                                                    : ''}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        ))}
                    </section>

                    <div className="flex flex-wrap gap-2">
                        {can.edit && page.state === 'published_with_draft' && (
                            <Button
                                variant="outline"
                                onClick={() => post(`${base}/discard`)}
                            >
                                <Icon icon={Undo2} />
                                {t('site.editor.discard')}
                            </Button>
                        )}
                        {can.unpublish &&
                            ['published', 'published_with_draft'].includes(
                                page.state,
                            ) && (
                                <Button
                                    variant="outline"
                                    onClick={() => post(`${base}/unpublish`)}
                                >
                                    {t('site.editor.unpublish')}
                                </Button>
                            )}
                        {can.archive && (
                            <Button
                                variant="outline"
                                onClick={() => post(`${base}/archive`)}
                            >
                                <Icon icon={Archive} />
                                {t('site.pages.archive')}
                            </Button>
                        )}
                        {can.restore && (
                            <Button
                                variant="outline"
                                onClick={() => post(`${base}/restore`)}
                            >
                                <Icon icon={RotateCcw} />
                                {t('site.pages.restore')}
                            </Button>
                        )}
                    </div>
                </aside>
            </div>

            <Dialog open={publishing} onOpenChange={setPublishing}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('site.publish.title')}</DialogTitle>
                        <DialogDescription>
                            {t('site.publish.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3">
                        {(['ar', 'en'] as const).map((l) => (
                            <div
                                key={l}
                                className={cn(
                                    'rounded-md border p-3',
                                    readiness[l].complete
                                        ? 'border-stroke-weak'
                                        : 'border-warning-text',
                                )}
                            >
                                <CheckboxField
                                    label={
                                        l === 'ar'
                                            ? t('site.editor.lang_ar')
                                            : t('site.editor.lang_en')
                                    }
                                    checked={chosen.includes(l)}
                                    disabled={!readiness[l].has_draft}
                                    onChange={(e) =>
                                        setChosen(
                                            e.target.checked
                                                ? [...chosen, l]
                                                : chosen.filter((x) => x !== l),
                                        )
                                    }
                                />
                                {!readiness[l].has_draft ? (
                                    <p className="mt-1 text-caption text-weak">
                                        {t('site.publish.no_changes')}
                                    </p>
                                ) : !readiness[l].complete ? (
                                    <p className="mt-1 text-caption text-warning-text">
                                        {t('site.publish.incomplete', {
                                            count: readiness[l].issues.length,
                                        })}
                                    </p>
                                ) : null}
                            </div>
                        ))}
                        <p className="text-caption text-weak">
                            {t('site.publish.keep_hint')}
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPublishing(false)}
                        >
                            {t('ui.cancel')}
                        </Button>
                        <Button
                            data-test="publish-now"
                            disabled={chosen.length === 0}
                            onClick={() => {
                                post(`${base}/publish`, {
                                    locales: chosen,
                                    only_complete: true,
                                });
                                setPublishing(false);
                            }}
                        >
                            {t('site.publish.complete_now')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

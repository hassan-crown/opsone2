import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ImagePlus } from 'lucide-react';
import { useRef } from 'react';
import type { ReactNode } from 'react';
import MaterialController from '@/actions/App/Http/Controllers/Catalog/MaterialController';
import ProductController from '@/actions/App/Http/Controllers/Catalog/ProductController';
import ReadyProductController from '@/actions/App/Http/Controllers/Catalog/ReadyProductController';
import { TextField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    CatalogTabs as Tabs,
    ImageFile,
    Page,
    VersionRow,
} from '@/types/catalog';

export type CatalogTab = keyof Tabs;

const TAB_HREF: Record<CatalogTab, string> = {
    products: ProductController.index.url(),
    ready: ReadyProductController.index.url(),
    materials: MaterialController.index.url(),
};

/** Section tabs of the single "Catalog" area (real links). Only sections the viewer may manage are listed. */
export function CatalogTabs({
    active,
    tabs,
}: {
    active: CatalogTab;
    tabs: Tabs;
}) {
    const t = useT();
    const items = (['products', 'ready', 'materials'] as const).filter(
        (key) => tabs[key],
    );

    return (
        <nav
            aria-label={t('catalog.tabs')}
            className="mb-4 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b border-stroke-weak"
        >
            {items.map((key) => (
                <Link
                    key={key}
                    href={TAB_HREF[key]}
                    aria-current={key === active ? 'page' : undefined}
                    className={cn(
                        'relative inline-flex min-h-11 shrink-0 items-center rounded-t-md px-4 text-control text-weak hover:bg-hover hover:text-strong focus-visible:-outline-offset-2',
                        key === active &&
                            'font-semibold text-strong after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-xs after:bg-brand',
                    )}
                >
                    {t(`catalog.tab_${key}` as TranslationKey)}
                </Link>
            ))}
        </nav>
    );
}

const STATUS_VARIANT = {
    active: 'success',
    published: 'success',
    draft: 'warning',
    disabled: 'neutral',
} as const;

export function StatusBadge({ status }: { status: string }) {
    const t = useT();

    return (
        <Badge
            variant={
                STATUS_VARIANT[status as keyof typeof STATUS_VARIANT] ??
                'neutral'
            }
        >
            {t(`catalog.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** Arabic (required) and English (optional) inputs of one bilingual value; names are `field[ar]` / `field[en]`. */
export function BilingualFields({
    label,
    value,
    onChange,
    errors,
    required,
    multiline,
}: {
    label: string;
    value: { ar: string | null; en: string | null };
    onChange: (next: { ar: string; en: string }) => void;
    errors: { ar?: string; en?: string };
    required?: boolean;
    multiline?: boolean;
}) {
    const t = useT();
    const Component = multiline ? TextareaField : TextField;

    return (
        <div className="grid gap-4 md:grid-cols-2">
            <Component
                label={`${label} — ${t('catalog.lang_ar')}`}
                value={value.ar ?? ''}
                onChange={(e) =>
                    onChange({ ar: e.target.value, en: value.en ?? '' })
                }
                lang="ar"
                dir="rtl"
                required={required}
                optional={!required}
                error={errors.ar}
            />
            <Component
                label={`${label} — ${t('catalog.lang_en')}`}
                value={value.en ?? ''}
                onChange={(e) =>
                    onChange({ ar: value.ar ?? '', en: e.target.value })
                }
                lang="en"
                dir="ltr"
                optional
                error={errors.en}
            />
        </div>
    );
}

/** Filter bar of a list: submits as a GET so the server filters and paginates. */
export function FilterBar({
    url,
    children,
    reset,
}: {
    url: string;
    children: ReactNode;
    reset: boolean;
}) {
    const t = useT();

    return (
        <form
            role="search"
            aria-label={t('catalog.filters')}
            className="mb-4 flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                const data: Record<string, string> = {};
                new FormData(event.currentTarget).forEach((value, key) => {
                    if (typeof value === 'string' && value !== '') {
                        data[key] = value;
                    }
                });
                router.get(url, data, { preserveState: true });
            }}
        >
            {children}
            <Button type="submit" variant="secondary">
                {t('catalog.apply')}
            </Button>
            {reset && (
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => router.get(url)}
                >
                    {t('catalog.reset')}
                </Button>
            )}
        </form>
    );
}

export function Pager({
    page,
    url,
    query,
}: {
    page: Page<unknown>;
    url: string;
    query: Record<string, string | null>;
}) {
    const t = useT();
    const { locale } = useLocale();

    if (page.last_page <= 1) {
        return null;
    }

    const go = (target: number) =>
        router.get(
            url,
            {
                ...Object.fromEntries(
                    Object.entries(query).filter(([, v]) => v),
                ),
                page: target,
            },
            { preserveState: true },
        );
    const Prev = locale === 'ar' ? ChevronRight : ChevronLeft;
    const Next = locale === 'ar' ? ChevronLeft : ChevronRight;

    return (
        <nav
            aria-label={t('catalog.pagination')}
            className="mt-4 flex flex-wrap items-center justify-between gap-3"
        >
            <span className="text-body text-weak tabular-nums">
                {t('catalog.page_of', {
                    page: formatNumber(page.current_page),
                    pages: formatNumber(page.last_page),
                    total: formatNumber(page.total),
                })}
            </span>
            <span className="flex gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={page.prev_page_url === null}
                    onClick={() => go(page.current_page - 1)}
                >
                    <Icon icon={Prev} />
                    {t('catalog.previous')}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    disabled={page.next_page_url === null}
                    onClick={() => go(page.current_page + 1)}
                >
                    {t('catalog.next')}
                    <Icon icon={Next} />
                </Button>
            </span>
        </nav>
    );
}

/** Images of a row (private attachments, short-lived signed links) with an upload button. */
export function ImagesPanel({
    images,
    uploadUrl,
    canUpload,
}: {
    images: ImageFile[];
    uploadUrl: string | null;
    canUpload: boolean;
}) {
    const t = useT();
    const input = useRef<HTMLInputElement>(null);

    return (
        <section aria-labelledby="catalog-images" className="grid gap-3">
            <h2 id="catalog-images" className="text-heading text-strong">
                {t('catalog.images')}
            </h2>
            {images.length === 0 ? (
                <p className="text-body text-weak">
                    {t('catalog.images_none')}
                </p>
            ) : (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    {images.map((image) => (
                        <li
                            key={image.uuid}
                            className="overflow-hidden rounded-md border border-stroke-weak bg-fill"
                        >
                            <img
                                src={image.url}
                                alt={image.name}
                                loading="lazy"
                                className="aspect-square w-full object-cover"
                            />
                        </li>
                    ))}
                </ul>
            )}
            {canUpload && uploadUrl && (
                <div>
                    <input
                        ref={input}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="sr-only"
                        aria-label={t('catalog.image_add')}
                        data-test="image-input"
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            if (file) {
                                router.post(
                                    uploadUrl,
                                    { file },
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                    },
                                );
                            }
                            event.target.value = '';
                        }}
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => input.current?.click()}
                    >
                        <Icon icon={ImagePlus} />
                        {t('catalog.image_add')}
                    </Button>
                </div>
            )}
        </section>
    );
}

/** Version history: each save that changes the specification adds one; orders freeze a specific version. */
export function VersionsPanel({
    versions,
    current,
    summary,
}: {
    versions: VersionRow[];
    current: number;
    summary: (payload: Record<string, unknown>) => string;
}) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <section aria-labelledby="catalog-versions" className="grid gap-3">
            <div>
                <h2 id="catalog-versions" className="text-heading text-strong">
                    {t('catalog.versions')}
                </h2>
                <p className="text-caption text-weak">
                    {t('catalog.versions_hint')}
                </p>
            </div>
            <ol className="grid gap-2">
                {versions.map((version) => (
                    <li
                        key={version.id}
                        data-test="version-row"
                        className="flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-md border border-stroke-weak px-3 py-2"
                    >
                        <span className="font-semibold text-strong tabular-nums">
                            {t('catalog.version_n', {
                                n: formatNumber(version.version),
                            })}
                        </span>
                        {version.version === current && (
                            <Badge variant="brand">
                                {t('catalog.version_current')}
                            </Badge>
                        )}
                        <span className="text-body text-weak">
                            {summary(version.payload)}
                        </span>
                        <span className="ms-auto text-caption text-weak">
                            {new Date(version.created_at).toLocaleDateString(
                                locale === 'ar' ? 'ar-SA-u-nu-latn' : 'en-GB',
                            )}
                            {version.author ? ` · ${version.author}` : ''}
                        </span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

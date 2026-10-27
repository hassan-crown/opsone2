import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import ImageController from '@/actions/App/Http/Controllers/Catalog/ImageController';
import ReadyProductController from '@/actions/App/Http/Controllers/Catalog/ReadyProductController';
import {
    BilingualFields,
    CatalogTabs,
    ImagesPanel,
    StatusBadge,
    VersionsPanel,
} from '@/components/catalog/shared';
import { Amount } from '@/components/opsone/amount';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
import type {
    CatalogTabs as Tabs,
    ImageFile,
    Text,
    VersionRow,
} from '@/types/catalog';

type Variant = {
    id: number;
    code: string;
    name: Text;
    price_minor: number;
    is_default: boolean;
    status: 'draft' | 'published' | 'disabled';
    version: number;
};

type Product = {
    id: number;
    name: Text;
    description: Text;
    status: 'draft' | 'published' | 'disabled';
    version: number;
    images: ImageFile[];
    versions: VersionRow[];
    variants_list: Variant[];
};

const STATUSES = ['draft', 'published', 'disabled'] as const;

/** Whole minor units to the `12.50` form the price input takes (string arithmetic, no floats). */
const toInput = (minor: number) =>
    `${Math.floor(minor / 100)}.${String(minor % 100).padStart(2, '0')}`;

export default function ReadyEdit({
    product,
    tabs,
}: {
    product: Product | null;
    tabs: Tabs;
}) {
    const t = useT();
    const [variant, setVariant] = useState<Variant | null>(null);
    const [variantOpen, setVariantOpen] = useState(false);
    const form = useForm({
        name: { ar: product?.name.ar ?? '', en: product?.name.en ?? '' },
        description: {
            ar: product?.description.ar ?? '',
            en: product?.description.en ?? '',
        },
    });
    const errors = form.errors as Record<string, string | undefined>;
    const changeStatus = (url: string, status: string) =>
        router.post(url, { status }, { preserveScroll: true });

    return (
        <>
            <Head
                title={product ? product.name.label : t('catalog.ready.new')}
            />
            <PageHeader
                title={product ? product.name.label : t('catalog.ready.new')}
                description={t('catalog.ready.form_description')}
                back={
                    <Link
                        href={ReadyProductController.index.url()}
                        className="inline-flex items-center gap-1 text-link hover:underline"
                    >
                        <Icon icon={ArrowLeft} className="rtl:rotate-180" />
                        {t('catalog.ready.title')}
                    </Link>
                }
                actions={
                    product && (
                        <>
                            <StatusBadge status={product.status} />
                            {product.status !== 'published' && (
                                <Button
                                    type="button"
                                    data-test="publish-ready"
                                    onClick={() =>
                                        changeStatus(
                                            ReadyProductController.status.url({
                                                ready: product.id,
                                            }),
                                            'published',
                                        )
                                    }
                                >
                                    {t('catalog.ready.publish')}
                                </Button>
                            )}
                            {product.status !== 'disabled' && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    data-test="disable-ready"
                                    onClick={() =>
                                        changeStatus(
                                            ReadyProductController.status.url({
                                                ready: product.id,
                                            }),
                                            'disabled',
                                        )
                                    }
                                >
                                    {t('catalog.ready.stop_sale')}
                                </Button>
                            )}
                        </>
                    )
                }
            />
            <CatalogTabs active="ready" tabs={tabs} />
            <form
                className="grid gap-6"
                data-test="ready-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (product) {
                        form.put(
                            ReadyProductController.update.url({
                                ready: product.id,
                            }),
                        );
                    } else {
                        form.post(ReadyProductController.store.url());
                    }
                }}
            >
                <Card className="gap-4">
                    <h2 className="text-heading text-strong">
                        {t('catalog.section_data')}
                    </h2>
                    <BilingualFields
                        label={t('catalog.name')}
                        value={form.data.name}
                        onChange={(v) => form.setData('name', v)}
                        errors={{
                            ar: errors['name.ar'],
                            en: errors['name.en'],
                        }}
                        required
                    />
                    <BilingualFields
                        label={t('catalog.description')}
                        value={form.data.description}
                        onChange={(v) => form.setData('description', v)}
                        errors={{
                            ar: errors['description.ar'],
                            en: errors['description.en'],
                        }}
                        multiline
                    />
                    <div>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="save-ready"
                        >
                            {t('catalog.ready.save')}
                        </Button>
                    </div>
                </Card>
            </form>

            {product && (
                <div className="mt-8 grid gap-8">
                    <section
                        aria-labelledby="catalog-variants"
                        className="grid gap-3"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2
                                    id="catalog-variants"
                                    className="text-heading text-strong"
                                >
                                    {t('catalog.variant.title')}
                                </h2>
                                <p className="text-caption text-weak">
                                    {t('catalog.variant.hint')}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="secondary"
                                data-test="add-variant"
                                onClick={() => {
                                    setVariant(null);
                                    setVariantOpen(true);
                                }}
                            >
                                <Icon icon={Plus} />
                                {t('catalog.variant.add')}
                            </Button>
                        </div>
                        {product.variants_list.length === 0 ? (
                            <p className="text-body text-weak">
                                {t('catalog.variant.none')}
                            </p>
                        ) : (
                            <Card className="gap-0 p-0 md:p-0">
                                <TableWrap>
                                    <Table
                                        caption={t('catalog.variant.caption')}
                                    >
                                        <thead>
                                            <tr>
                                                <Th>
                                                    {t(
                                                        'catalog.variant.col_variant',
                                                    )}
                                                </Th>
                                                <Th>
                                                    {t(
                                                        'catalog.variant.col_code',
                                                    )}
                                                </Th>
                                                <Th>
                                                    {t(
                                                        'catalog.variant.col_price',
                                                    )}
                                                </Th>
                                                <Th>
                                                    {t('catalog.col_status')}
                                                </Th>
                                                <Th>
                                                    {t(
                                                        'catalog.variant.col_available',
                                                    )}
                                                </Th>
                                                <Th className="text-end">
                                                    {t('catalog.col_actions')}
                                                </Th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {product.variants_list.map((v) => (
                                                <tr
                                                    key={v.id}
                                                    data-test="variant-row"
                                                >
                                                    <Td className="font-semibold">
                                                        {v.is_default
                                                            ? t(
                                                                  'catalog.variant.default_name',
                                                              )
                                                            : v.name.label}
                                                    </Td>
                                                    <Td
                                                        dir="ltr"
                                                        className="text-start"
                                                    >
                                                        {v.code}
                                                    </Td>
                                                    <Td>
                                                        <Amount
                                                            value={
                                                                v.price_minor /
                                                                100
                                                            }
                                                        />
                                                    </Td>
                                                    <Td>
                                                        <StatusBadge
                                                            status={v.status}
                                                        />
                                                    </Td>
                                                    <Td className="text-weak">
                                                        —
                                                    </Td>
                                                    <Td className="text-end">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <select
                                                                aria-label={t(
                                                                    'catalog.variant.status_label',
                                                                    {
                                                                        code: v.code,
                                                                    },
                                                                )}
                                                                value={v.status}
                                                                className="field-control min-h-9 w-32 text-control"
                                                                onChange={(e) =>
                                                                    changeStatus(
                                                                        ReadyProductController.variantStatus.url(
                                                                            {
                                                                                ready: product.id,
                                                                                variant:
                                                                                    v.id,
                                                                            },
                                                                        ),
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                            >
                                                                {STATUSES.map(
                                                                    (s) => (
                                                                        <option
                                                                            key={
                                                                                s
                                                                            }
                                                                            value={
                                                                                s
                                                                            }
                                                                        >
                                                                            {t(
                                                                                `catalog.status.${s}` as TranslationKey,
                                                                            )}
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={t(
                                                                    'catalog.edit',
                                                                )}
                                                                onClick={() => {
                                                                    setVariant(
                                                                        v,
                                                                    );
                                                                    setVariantOpen(
                                                                        true,
                                                                    );
                                                                }}
                                                            >
                                                                <Icon
                                                                    icon={
                                                                        Pencil
                                                                    }
                                                                />
                                                            </Button>
                                                        </div>
                                                    </Td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </TableWrap>
                            </Card>
                        )}
                        <p className="text-caption text-weak">
                            {t('catalog.variant.stock_note')}
                        </p>
                    </section>
                    <ImagesPanel
                        images={product.images}
                        uploadUrl={ImageController.ready.url({
                            ready: product.id,
                        })}
                        canUpload
                    />
                    <VersionsPanel
                        versions={product.versions}
                        current={product.version}
                        summary={(p) =>
                            (p.name as { ar?: string; en?: string })?.ar ??
                            (p.name as { en?: string })?.en ??
                            ''
                        }
                    />
                </div>
            )}

            {product && (
                <Dialog open={variantOpen} onOpenChange={setVariantOpen}>
                    <DialogContent size="md" aria-describedby={undefined}>
                        <DialogHeader>
                            <DialogTitle>
                                {variant
                                    ? t('catalog.variant.edit_title')
                                    : t('catalog.variant.new_title')}
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                {t('catalog.variant.hint')}
                            </DialogDescription>
                        </DialogHeader>
                        <VariantForm
                            key={variant?.id ?? 'new'}
                            productId={product.id}
                            variant={variant}
                            hasVariants={product.variants_list.length > 0}
                            onDone={() => setVariantOpen(false)}
                        />
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function VariantForm({
    productId,
    variant,
    hasVariants,
    onDone,
}: {
    productId: number;
    variant: Variant | null;
    hasVariants: boolean;
    onDone: () => void;
}) {
    const t = useT();
    const form = useForm({
        code: variant?.code ?? '',
        name: { ar: variant?.name.ar ?? '', en: variant?.name.en ?? '' },
        price: variant ? toInput(variant.price_minor) : '',
        is_default: variant?.is_default ?? !hasVariants,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            data-test="variant-form"
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                const options = { onSuccess: onDone, preserveScroll: true };
                if (variant) {
                    form.put(
                        ReadyProductController.updateVariant.url({
                            ready: productId,
                            variant: variant.id,
                        }),
                        options,
                    );
                } else {
                    form.post(
                        ReadyProductController.storeVariant.url({
                            ready: productId,
                        }),
                        options,
                    );
                }
            }}
        >
            {errors.product && (
                <p role="alert" className="text-body text-danger-text">
                    {errors.product}
                </p>
            )}
            <TextField
                label={t('catalog.variant.col_code')}
                dir="ltr"
                value={form.data.code}
                onChange={(e) => form.setData('code', e.target.value)}
                required
                autoFocus
                error={errors.code}
            />
            <BilingualFields
                label={t('catalog.variant.name')}
                value={form.data.name}
                onChange={(v) => form.setData('name', v)}
                errors={{ ar: errors['name.ar'], en: errors['name.en'] }}
            />
            <TextField
                label={t('catalog.variant.col_price')}
                hint={t('catalog.variant.price_hint')}
                dir="ltr"
                inputMode="decimal"
                value={form.data.price}
                onChange={(e) => form.setData('price', e.target.value)}
                required
                error={errors.price}
            />
            <CheckboxField
                label={t('catalog.variant.is_default')}
                hint={t('catalog.variant.is_default_hint')}
                checked={form.data.is_default}
                onChange={(e) => form.setData('is_default', e.target.checked)}
                error={errors.is_default}
            />
            <DialogFooter>
                <Button type="button" variant="secondary" onClick={onDone}>
                    {t('catalog.cancel')}
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing}
                    data-test="save-variant"
                >
                    {t('catalog.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}

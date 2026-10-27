import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Catalog/ProductController';
import ImageController from '@/actions/App/Http/Controllers/Catalog/ImageController';
import {
    BilingualFields,
    CatalogTabs,
    ImagesPanel,
    StatusBadge,
    VersionsPanel,
} from '@/components/catalog/shared';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
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
    OptionGroup,
    Text,
    VersionRow,
} from '@/types/catalog';

type Product = {
    id: number;
    name: Text;
    description: Text;
    category: string | null;
    pricing_basis: string;
    reference_price: string;
    option_groups: OptionGroup[];
    status: 'active' | 'disabled';
    version: number;
    images: ImageFile[];
    versions: VersionRow[];
};

type Material = {
    id: number;
    name: string;
    group: string | null;
    selectable: boolean;
};

type Props = {
    product: Product | null;
    materials: Material[];
    pricingBases: string[];
    tabs: Tabs;
};

const emptyGroup = (): OptionGroup => ({
    key: '',
    label: { ar: '', en: '' },
    type: 'single',
    required: false,
    unit: null,
    values: [],
    material_ids: [],
});

const GROUP_TYPES = [
    'single',
    'multiple',
    'text',
    'number',
    'attachment',
] as const;

export default function ProductForm({
    product,
    materials,
    pricingBases,
    tabs,
}: Props) {
    const t = useT();
    const [confirmDisable, setConfirmDisable] = useState(false);
    const form = useForm({
        category: product?.category ?? '',
        name: { ar: product?.name.ar ?? '', en: product?.name.en ?? '' },
        description: {
            ar: product?.description.ar ?? '',
            en: product?.description.en ?? '',
        },
        pricing_basis: product?.pricing_basis ?? pricingBases[0],
        reference_price: product?.reference_price ?? '',
        option_groups: (product?.option_groups ?? []) as OptionGroup[],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = () =>
        product
            ? form.put(ProductController.update.url({ product: product.id }))
            : form.post(ProductController.store.url());

    const setGroup = (index: number, patch: Partial<OptionGroup>) =>
        form.setData(
            'option_groups',
            form.data.option_groups.map((g, i) =>
                i === index ? { ...g, ...patch } : g,
            ),
        );

    return (
        <>
            <Head
                title={product ? product.name.label : t('catalog.products.new')}
            />
            <PageHeader
                title={product ? product.name.label : t('catalog.products.new')}
                description={t('catalog.product.description')}
                back={
                    <Link
                        href={ProductController.index.url()}
                        className="inline-flex items-center gap-1 text-link hover:underline"
                    >
                        <Icon icon={ArrowLeft} className="rtl:rotate-180" />
                        {t('catalog.products.title')}
                    </Link>
                }
                actions={
                    product && (
                        <>
                            <StatusBadge status={product.status} />
                            {product.status === 'active' ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setConfirmDisable(true)}
                                    data-test="disable-product"
                                >
                                    <Icon icon={Lock} />
                                    {t('catalog.disable')}
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(
                                            ProductController.enable.url({
                                                product: product.id,
                                            }),
                                        )
                                    }
                                    data-test="enable-product"
                                >
                                    <Icon icon={RotateCcw} />
                                    {t('catalog.enable')}
                                </Button>
                            )}
                        </>
                    )
                }
            />
            <CatalogTabs active="products" tabs={tabs} />
            <form
                className="grid gap-6"
                data-test="product-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
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
                    <div className="grid gap-4 md:grid-cols-3">
                        <TextField
                            label={t('catalog.category')}
                            value={form.data.category}
                            onChange={(e) =>
                                form.setData('category', e.target.value)
                            }
                            optional
                            error={errors.category}
                        />
                        <SelectField
                            label={t('catalog.products.col_basis')}
                            value={form.data.pricing_basis}
                            onChange={(e) =>
                                form.setData('pricing_basis', e.target.value)
                            }
                            options={pricingBases.map((b) => ({
                                value: b,
                                label: t(
                                    `catalog.basis.${b}` as TranslationKey,
                                ),
                            }))}
                            required
                            error={errors.pricing_basis}
                        />
                        <TextField
                            label={t('catalog.products.col_price')}
                            hint={t('catalog.products.price_hint')}
                            value={form.data.reference_price}
                            onChange={(e) =>
                                form.setData('reference_price', e.target.value)
                            }
                            inputMode="decimal"
                            dir="ltr"
                            optional
                            error={errors.reference_price}
                        />
                    </div>
                </Card>

                <Card className="gap-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-heading text-strong">
                                {t('catalog.groups.title')}
                            </h2>
                            <p className="text-caption text-weak">
                                {t('catalog.groups.hint')}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            data-test="add-group"
                            onClick={() =>
                                form.setData('option_groups', [
                                    ...form.data.option_groups,
                                    emptyGroup(),
                                ])
                            }
                        >
                            <Icon icon={Plus} />
                            {t('catalog.groups.add')}
                        </Button>
                    </div>
                    {errors.option_groups && (
                        <p role="alert" className="text-body text-danger-text">
                            {errors.option_groups}
                        </p>
                    )}
                    {form.data.option_groups.length === 0 && (
                        <p className="text-body text-weak">
                            {t('catalog.groups.none')}
                        </p>
                    )}
                    {form.data.option_groups.map((group, index) => (
                        <GroupEditor
                            key={group.key || `new-${index}`}
                            index={index}
                            group={group}
                            materials={materials}
                            errors={errors}
                            onChange={(patch) => setGroup(index, patch)}
                            onRemove={() =>
                                form.setData(
                                    'option_groups',
                                    form.data.option_groups.filter(
                                        (_, i) => i !== index,
                                    ),
                                )
                            }
                        />
                    ))}
                </Card>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="save-product"
                    >
                        {t('catalog.products.save')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={ProductController.index.url()}>
                            {t('catalog.cancel')}
                        </Link>
                    </Button>
                </div>
            </form>

            {product && (
                <div className="mt-8 grid gap-8">
                    <ImagesPanel
                        images={product.images}
                        uploadUrl={ImageController.product.url({
                            product: product.id,
                        })}
                        canUpload
                    />
                    <VersionsPanel
                        versions={product.versions}
                        current={product.version}
                        summary={(p) =>
                            `${(p.name as { ar?: string; en?: string })?.ar ?? (p.name as { en?: string })?.en ?? ''} · ${(p.option_groups as unknown[])?.length ?? 0} ${t('catalog.groups.count')}`
                        }
                    />
                    <p className="text-caption text-weak">
                        {t('catalog.products.frozen_note')}
                    </p>
                </div>
            )}

            {product && (
                <Dialog open={confirmDisable} onOpenChange={setConfirmDisable}>
                    <DialogContent size="sm" aria-describedby={undefined}>
                        <DialogHeader>
                            <DialogTitle>
                                {t('catalog.products.disable_title', {
                                    name: product.name.label,
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {t('catalog.disable_desc')}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setConfirmDisable(false)}
                            >
                                {t('catalog.cancel')}
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                data-test="confirm-disable"
                                onClick={() =>
                                    router.post(
                                        ProductController.disable.url({
                                            product: product.id,
                                        }),
                                        {},
                                        {
                                            onFinish: () =>
                                                setConfirmDisable(false),
                                        },
                                    )
                                }
                            >
                                {t('catalog.disable')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function GroupEditor({
    index,
    group,
    materials,
    errors,
    onChange,
    onRemove,
}: {
    index: number;
    group: OptionGroup;
    materials: Material[];
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<OptionGroup>) => void;
    onRemove: () => void;
}) {
    const t = useT();
    const choice = group.type === 'single' || group.type === 'multiple';
    const at = (field: string) => errors[`option_groups.${index}.${field}`];

    return (
        <fieldset
            data-test="option-group"
            className="m-0 grid gap-4 rounded-md border border-stroke-weak p-4"
        >
            <legend className="px-1 text-label text-strong">
                {t('catalog.groups.n', { n: index + 1 })}
            </legend>
            <BilingualFields
                label={t('catalog.groups.label')}
                value={group.label}
                onChange={(label) => onChange({ label })}
                errors={{
                    ar: at('label.ar'),
                    en: at('label.en'),
                }}
                required
            />
            <div className="grid gap-4 md:grid-cols-3">
                <SelectField
                    label={t('catalog.groups.type')}
                    value={group.type}
                    onChange={(e) =>
                        onChange({
                            type: e.target.value as OptionGroup['type'],
                        })
                    }
                    options={GROUP_TYPES.map((type) => ({
                        value: type,
                        label: t(
                            `catalog.group_type.${type}` as TranslationKey,
                        ),
                    }))}
                    error={at('type')}
                />
                {group.type === 'number' && (
                    <TextField
                        label={t('catalog.groups.unit')}
                        value={group.unit ?? ''}
                        onChange={(e) => onChange({ unit: e.target.value })}
                        required
                        error={at('unit')}
                    />
                )}
                <CheckboxField
                    label={t('catalog.groups.required')}
                    checked={group.required}
                    onChange={(e) => onChange({ required: e.target.checked })}
                />
            </div>
            {choice && (
                <div className="grid gap-3">
                    {at('values') && (
                        <p role="alert" className="text-body text-danger-text">
                            {at('values')}
                        </p>
                    )}
                    <div className="grid gap-2">
                        <span className="text-label text-strong">
                            {t('catalog.groups.values')}
                        </span>
                        {group.values.map((value, i) => (
                            <div
                                key={i}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <input
                                    aria-label={`${t('catalog.groups.values')} ${i + 1} — ${t('catalog.lang_ar')}`}
                                    className="field-control min-w-0 flex-1 text-control"
                                    lang="ar"
                                    dir="rtl"
                                    value={value.ar ?? ''}
                                    onChange={(e) =>
                                        onChange({
                                            values: group.values.map((v, j) =>
                                                j === i
                                                    ? {
                                                          ...v,
                                                          ar: e.target.value,
                                                      }
                                                    : v,
                                            ),
                                        })
                                    }
                                />
                                <input
                                    aria-label={`${t('catalog.groups.values')} ${i + 1} — ${t('catalog.lang_en')}`}
                                    className="field-control min-w-0 flex-1 text-control"
                                    lang="en"
                                    dir="ltr"
                                    value={value.en ?? ''}
                                    onChange={(e) =>
                                        onChange({
                                            values: group.values.map((v, j) =>
                                                j === i
                                                    ? {
                                                          ...v,
                                                          en: e.target.value,
                                                      }
                                                    : v,
                                            ),
                                        })
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t(
                                        'catalog.groups.remove_value',
                                    )}
                                    onClick={() =>
                                        onChange({
                                            values: group.values.filter(
                                                (_, j) => j !== i,
                                            ),
                                        })
                                    }
                                >
                                    <Icon icon={Trash2} />
                                </Button>
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={() =>
                                    onChange({
                                        values: [
                                            ...group.values,
                                            { ar: '', en: '' },
                                        ],
                                    })
                                }
                            >
                                <Icon icon={Plus} />
                                {t('catalog.groups.add_value')}
                            </Button>
                        </div>
                    </div>
                    {materials.length > 0 && (
                        <fieldset className="m-0 grid min-w-0 gap-1 border-0 p-0">
                            <legend className="mb-1 text-label text-strong">
                                {t('catalog.groups.materials')}
                            </legend>
                            <div className="grid gap-x-4 sm:grid-cols-2">
                                {materials.map((material) => {
                                    const checked = group.material_ids.includes(
                                        material.id,
                                    );

                                    return (
                                        <CheckboxField
                                            key={material.id}
                                            label={
                                                material.selectable
                                                    ? material.name
                                                    : `${material.name} (${t('catalog.status.disabled')})`
                                            }
                                            checked={checked}
                                            disabled={
                                                !material.selectable && !checked
                                            }
                                            onChange={(e) =>
                                                onChange({
                                                    material_ids: e.target
                                                        .checked
                                                        ? [
                                                              ...group.material_ids,
                                                              material.id,
                                                          ]
                                                        : group.material_ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  material.id,
                                                          ),
                                                })
                                            }
                                        />
                                    );
                                })}
                            </div>
                        </fieldset>
                    )}
                </div>
            )}
            <div>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={onRemove}
                    data-test="remove-group"
                >
                    <Icon icon={Trash2} />
                    {t('catalog.groups.remove')}
                </Button>
            </div>
        </fieldset>
    );
}

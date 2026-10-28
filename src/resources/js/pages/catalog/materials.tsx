import { Head, router, useForm } from '@inertiajs/react';
import { Boxes, Lock, Pencil, Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import ImageController from '@/actions/App/Http/Controllers/Catalog/ImageController';
import MaterialController from '@/actions/App/Http/Controllers/Catalog/MaterialController';
import {
    BilingualFields,
    CatalogTabs,
    FilterBar,
    ImagesPanel,
    Pager,
    StatusBadge,
} from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { RowMenu } from '@/components/org/row-menu';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    CatalogTabs as Tabs,
    ImageFile,
    Page,
    Text,
} from '@/types/catalog';

type Row = {
    id: number;
    name: Text;
    description: Text;
    group: string | null;
    unit: string;
    availability: 'available' | 'on_request' | 'unavailable';
    supplier_name: string | null;
    track_quantity: boolean;
    status: 'active' | 'disabled';
    images: ImageFile[];
};

type Props = {
    materials: Page<Row>;
    filters: {
        q: string;
        status: string | null;
        group: string | null;
        availability: string | null;
    };
    groups: string[];
    tabs: Tabs;
    canCreate: boolean;
};

const AVAILABILITY = ['available', 'on_request', 'unavailable'] as const;

export default function Materials({
    materials,
    filters,
    groups,
    tabs,
    canCreate,
}: Props) {
    const t = useT();
    const [editing, setEditing] = useState<Row | null>(null);
    const [open, setOpen] = useState(false);
    const [disabling, setDisabling] = useState<Row | null>(null);
    const filtered = Boolean(
        filters.q || filters.status || filters.group || filters.availability,
    );

    const openForm = (row: Row | null) => {
        setEditing(row);
        setOpen(true);
    };

    return (
        <>
            <Head title={t('catalog.materials.title')} />
            <PageHeader
                title={t('catalog.title')}
                description={t('catalog.materials.description')}
                actions={
                    canCreate && (
                        <Button
                            type="button"
                            onClick={() => openForm(null)}
                            data-test="add-material"
                        >
                            <Icon icon={Plus} />
                            {t('catalog.materials.add')}
                        </Button>
                    )
                }
            />
            <CatalogTabs active="materials" tabs={tabs} />
            <FilterBar url={MaterialController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('catalog.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-56"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-group">
                        {t('catalog.materials.group')}
                    </Label>
                    <select
                        id="f-group"
                        name="group"
                        defaultValue={filters.group ?? ''}
                        className="field-control w-40 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {groups.map((g) => (
                            <option key={g} value={g}>
                                {g}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-availability">
                        {t('catalog.materials.availability')}
                    </Label>
                    <select
                        id="f-availability"
                        name="availability"
                        defaultValue={filters.availability ?? ''}
                        className="field-control w-40 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {AVAILABILITY.map((a) => (
                            <option key={a} value={a}>
                                {t(
                                    `catalog.availability.${a}` as TranslationKey,
                                )}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">{t('catalog.col_status')}</Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-36 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        <option value="active">
                            {t('catalog.status.active')}
                        </option>
                        <option value="disabled">
                            {t('catalog.status.disabled')}
                        </option>
                    </select>
                </div>
            </FilterBar>
            {materials.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Boxes}
                        title={t(
                            filtered
                                ? 'catalog.no_results'
                                : 'catalog.materials.empty',
                        )}
                        description={
                            filtered
                                ? undefined
                                : t('catalog.materials.empty_desc')
                        }
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('catalog.materials.caption')}>
                            <thead>
                                <tr>
                                    <Th>
                                        {t('catalog.materials.col_material')}
                                    </Th>
                                    <Th>{t('catalog.materials.group')}</Th>
                                    <Th>{t('catalog.materials.unit')}</Th>
                                    <Th>
                                        {t('catalog.materials.availability')}
                                    </Th>
                                    <Th>{t('catalog.materials.supplier')}</Th>
                                    <Th>{t('catalog.materials.quantity')}</Th>
                                    <Th>{t('catalog.col_status')}</Th>
                                    <Th className="text-end">
                                        {t('catalog.col_actions')}
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {materials.data.map((m) => (
                                    <tr key={m.id} data-test="material-row">
                                        <Td className="font-semibold">
                                            {m.name.label}
                                        </Td>
                                        <Td>{m.group ?? '—'}</Td>
                                        <Td>{m.unit}</Td>
                                        <Td>
                                            <Badge
                                                variant={
                                                    m.availability ===
                                                    'available'
                                                        ? 'success'
                                                        : m.availability ===
                                                            'on_request'
                                                          ? 'warning'
                                                          : 'neutral'
                                                }
                                            >
                                                {t(
                                                    `catalog.availability.${m.availability}` as TranslationKey,
                                                )}
                                            </Badge>
                                        </Td>
                                        <Td>
                                            {m.supplier_name ??
                                                t(
                                                    'catalog.materials.no_supplier',
                                                )}
                                        </Td>
                                        <Td>
                                            {m.track_quantity
                                                ? t('catalog.materials.tracked')
                                                : t(
                                                      'catalog.materials.descriptive',
                                                  )}
                                        </Td>
                                        <Td>
                                            <StatusBadge status={m.status} />
                                        </Td>
                                        <Td className="text-end">
                                            <RowMenu
                                                label={t('catalog.row_actions')}
                                                items={
                                                    canCreate
                                                        ? [
                                                              {
                                                                  label: t(
                                                                      'catalog.edit',
                                                                  ),
                                                                  icon: Pencil,
                                                                  onSelect:
                                                                      () =>
                                                                          openForm(
                                                                              m,
                                                                          ),
                                                              },
                                                              m.status ===
                                                              'active'
                                                                  ? {
                                                                        label: t(
                                                                            'catalog.disable',
                                                                        ),
                                                                        icon: Lock,
                                                                        danger: true,
                                                                        separatorBefore: true,
                                                                        onSelect:
                                                                            () =>
                                                                                setDisabling(
                                                                                    m,
                                                                                ),
                                                                    }
                                                                  : {
                                                                        label: t(
                                                                            'catalog.enable',
                                                                        ),
                                                                        icon: RotateCcw,
                                                                        onSelect:
                                                                            () =>
                                                                                router.post(
                                                                                    MaterialController.enable.url(
                                                                                        {
                                                                                            material:
                                                                                                m.id,
                                                                                        },
                                                                                    ),
                                                                                ),
                                                                    },
                                                          ]
                                                        : []
                                                }
                                            />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <Pager
                page={materials}
                url={MaterialController.index.url()}
                query={filters}
            />
            <p className="mt-4 text-caption text-weak">
                {t('catalog.materials.stock_note')}
            </p>

            <MaterialDialog
                open={open}
                onOpenChange={setOpen}
                material={editing}
                groups={groups}
            />
            <Dialog
                open={disabling !== null}
                onOpenChange={(value) => !value && setDisabling(null)}
            >
                <DialogContent size="sm" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('catalog.materials.disable_title', {
                                name: disabling?.name.label ?? '',
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
                            onClick={() => setDisabling(null)}
                        >
                            {t('catalog.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="confirm-disable"
                            onClick={() =>
                                disabling &&
                                router.post(
                                    MaterialController.disable.url({
                                        material: disabling.id,
                                    }),
                                    {},
                                    { onFinish: () => setDisabling(null) },
                                )
                            }
                        >
                            {t('catalog.disable')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function MaterialDialog({
    open,
    onOpenChange,
    material,
    groups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    material: Row | null;
    groups: string[];
}) {
    const t = useT();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="lg" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {material
                            ? t('catalog.materials.edit_title')
                            : t('catalog.materials.new_title')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('catalog.materials.description')}
                    </DialogDescription>
                </DialogHeader>
                <MaterialForm
                    key={material?.id ?? 'new'}
                    material={material}
                    groups={groups}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function MaterialForm({
    material,
    groups,
    onDone,
}: {
    material: Row | null;
    groups: string[];
    onDone: () => void;
}) {
    const t = useT();
    const form = useForm({
        material_group: material?.group ?? '',
        name: { ar: material?.name.ar ?? '', en: material?.name.en ?? '' },
        description: {
            ar: material?.description.ar ?? '',
            en: material?.description.en ?? '',
        },
        unit: material?.unit ?? '',
        availability: material?.availability ?? 'available',
        supplier_name: material?.supplier_name ?? '',
        track_quantity: material?.track_quantity ?? false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            data-test="material-form"
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                const options = { onSuccess: onDone };
                if (material) {
                    form.put(
                        MaterialController.update.url({
                            material: material.id,
                        }),
                        options,
                    );
                } else {
                    form.post(MaterialController.store.url(), options);
                }
            }}
        >
            <BilingualFields
                label={t('catalog.name')}
                value={form.data.name}
                onChange={(v) => form.setData('name', v)}
                errors={{ ar: errors['name.ar'], en: errors['name.en'] }}
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
                    label={t('catalog.materials.group')}
                    list="material-groups"
                    value={form.data.material_group}
                    onChange={(e) =>
                        form.setData('material_group', e.target.value)
                    }
                    optional
                    error={errors.material_group}
                />
                <datalist id="material-groups">
                    {groups.map((g) => (
                        <option key={g} value={g} />
                    ))}
                </datalist>
                <TextField
                    label={t('catalog.materials.unit')}
                    value={form.data.unit}
                    onChange={(e) => form.setData('unit', e.target.value)}
                    required
                    error={errors.unit}
                />
                <SelectField
                    label={t('catalog.materials.availability')}
                    value={form.data.availability}
                    onChange={(e) =>
                        form.setData(
                            'availability',
                            e.target.value as Row['availability'],
                        )
                    }
                    options={AVAILABILITY.map((a) => ({
                        value: a,
                        label: t(`catalog.availability.${a}` as TranslationKey),
                    }))}
                    error={errors.availability}
                />
            </div>
            <TextField
                label={t('catalog.materials.supplier')}
                hint={t('catalog.materials.supplier_hint')}
                value={form.data.supplier_name}
                onChange={(e) => form.setData('supplier_name', e.target.value)}
                optional
                error={errors.supplier_name}
            />
            <CheckboxField
                label={t('catalog.materials.track')}
                hint={t('catalog.materials.track_hint')}
                checked={form.data.track_quantity}
                onChange={(e) =>
                    form.setData('track_quantity', e.target.checked)
                }
            />
            {material && (
                <ImagesPanel
                    images={material.images}
                    uploadUrl={ImageController.material.url({
                        material: material.id,
                    })}
                    canUpload
                />
            )}
            <DialogFooter>
                <Button type="button" variant="secondary" onClick={onDone}>
                    {t('catalog.cancel')}
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing}
                    data-test="save-material"
                >
                    {t('catalog.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}

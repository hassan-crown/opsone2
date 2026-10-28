import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import { Amount } from '@/components/opsone/amount';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { ItemSearch } from '@/components/procurement/item-search';
import {
    milliToInput,
    minorToInput,
    parseMoney,
    parseQty,
} from '@/components/procurement/quantity';
import {
    BackLink,
    FormErrors,
    unitLabel,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type {
    ItemResult,
    LocationOption,
    Option,
    PurchaseDetail,
    SupplierOption,
} from '@/types/procurement';

type Prefill = { item_type: string; item_id: number; quantity: string };

type Props = {
    suppliers: SupplierOption[];
    branches: Option[];
    members: Option[];
    locations: LocationOption[];
    canCost: boolean;
    purchase: PurchaseDetail | null;
    prefill: Prefill | null;
};

type LineDraft = {
    id: number | null;
    item_type: string;
    item_id: number;
    name: string;
    unit: string;
    quantity: string;
    unit_cost: string;
};

export default function PurchaseForm({
    suppliers,
    branches,
    members,
    locations,
    canCost,
    purchase,
    prefill,
}: Props) {
    const t = useT();
    const save = useIdempotencyKey();
    const confirm = useIdempotencyKey();

    const initialLines: LineDraft[] = purchase
        ? purchase.lines.map((l) => ({
              id: l.id,
              item_type: l.item_type,
              item_id: l.item_id,
              name: l.name.label,
              unit: l.unit,
              quantity: milliToInput(l.quantity_milli),
              unit_cost:
                  l.unit_cost_minor != null
                      ? minorToInput(l.unit_cost_minor)
                      : '',
          }))
        : prefill
          ? [
                {
                    id: null,
                    item_type: prefill.item_type,
                    item_id: prefill.item_id,
                    name: t('procurement.item_ref', { id: prefill.item_id }),
                    unit: '',
                    quantity: prefill.quantity,
                    unit_cost: '',
                },
            ]
          : [];

    const form = useForm({
        supplier_id: purchase ? String(purchase.supplier.id) : '',
        branch_id: purchase
            ? String(purchase.branch.id)
            : branches.length === 1
              ? String(branches[0].id)
              : '',
        location_id:
            purchase?.location_id != null ? String(purchase.location_id) : '',
        expected_on: purchase?.expected_on ?? '',
        responsible_id: purchase?.responsible
            ? String(purchase.responsible.id)
            : '',
        notes: purchase?.notes ?? '',
        lines: initialLines,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lines = form.data.lines;

    const supplierOptions = [...suppliers];
    if (
        purchase &&
        !supplierOptions.some((s) => s.id === purchase.supplier.id)
    ) {
        supplierOptions.push({
            id: purchase.supplier.id,
            name: purchase.supplier.name,
        });
    }

    const setLine = (index: number, patch: Partial<LineDraft>) =>
        form.setData(
            'lines',
            lines.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const addItem = (item: ItemResult) =>
        form.setData('lines', [
            ...lines,
            {
                id: null,
                item_type: item.item_type,
                item_id: item.item_id,
                name: item.name.label,
                unit: item.unit,
                quantity: '',
                unit_cost: '',
            },
        ]);

    const subtotal = lines.reduce((sum, l) => {
        const qty = parseQty(l.quantity);
        const cost = parseMoney(l.unit_cost);

        return qty && cost !== null
            ? sum + Math.round((qty * cost) / 1000)
            : sum;
    }, 0);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            supplier_id:
                data.supplier_id === '' ? null : Number(data.supplier_id),
            branch_id: data.branch_id === '' ? null : Number(data.branch_id),
            location_id:
                data.location_id === '' ? null : Number(data.location_id),
            expected_on: data.expected_on === '' ? null : data.expected_on,
            responsible_id:
                data.responsible_id === '' ? null : Number(data.responsible_id),
            notes: data.notes,
            lines: data.lines.map((l) => ({
                ...(l.id !== null ? { id: l.id } : {}),
                item_type: l.item_type,
                item_id: l.item_id,
                quantity: l.quantity.trim(),
                ...(canCost ? { unit_cost: l.unit_cost.trim() } : {}),
            })),
            idempotency_key: save.key,
            ...(purchase ? { lock_version: purchase.lock_version } : {}),
        }));
        const options = { preserveScroll: true, onError: save.renew };
        if (purchase) {
            form.put(
                PurchaseController.update.url({ purchase: purchase.id }),
                options,
            );
        } else {
            form.post(PurchaseController.store.url(), options);
        }
    };

    const confirmDraft = () => {
        if (!purchase) {
            return;
        }
        router.post(
            PurchaseController.confirm.url({ purchase: purchase.id }),
            {
                lock_version: purchase.lock_version,
                idempotency_key: confirm.key,
            },
            { onError: confirm.renew },
        );
    };

    const title = purchase
        ? t('procurement.edit_title', { number: purchase.number })
        : t('procurement.new_title');

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={t('procurement.form_description')}
                back={
                    <BackLink
                        href={
                            purchase
                                ? PurchaseController.show.url({
                                      purchase: purchase.id,
                                  })
                                : PurchaseController.index.url()
                        }
                        label={
                            purchase ? purchase.number : t('procurement.title')
                        }
                    />
                }
            />
            <form
                onSubmit={submit}
                data-test="purchase-form"
                className="grid gap-4"
            >
                <FormErrors errors={errors} />
                <Card className="gap-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <SelectField
                            label={t('procurement.col_supplier')}
                            placeholder={t('procurement.choose')}
                            value={form.data.supplier_id}
                            onChange={(e) =>
                                form.setData('supplier_id', e.target.value)
                            }
                            options={supplierOptions.map((s) => ({
                                value: String(s.id),
                                label: s.name.label,
                            }))}
                            required
                            error={errors.supplier_id}
                        />
                        <SelectField
                            label={t('procurement.col_branch')}
                            placeholder={t('procurement.choose')}
                            value={form.data.branch_id}
                            onChange={(e) =>
                                form.setData('branch_id', e.target.value)
                            }
                            options={branches.map((b) => ({
                                value: String(b.id),
                                label: b.name,
                            }))}
                            required
                            error={errors.branch_id}
                        />
                        {locations.length > 0 ? (
                            <SelectField
                                label={t('procurement.location')}
                                value={form.data.location_id}
                                onChange={(e) =>
                                    form.setData('location_id', e.target.value)
                                }
                                options={[
                                    { value: '', label: '—' },
                                    ...locations.map((l) => ({
                                        value: String(l.id),
                                        label: l.name,
                                    })),
                                ]}
                                optional
                                error={errors.location_id}
                            />
                        ) : (
                            <TextField
                                label={t('procurement.location_id')}
                                hint={t('procurement.location_id_hint')}
                                type="number"
                                min={1}
                                inputMode="numeric"
                                dir="ltr"
                                value={form.data.location_id}
                                onChange={(e) =>
                                    form.setData('location_id', e.target.value)
                                }
                                optional
                                error={errors.location_id}
                            />
                        )}
                        <TextField
                            label={t('procurement.col_expected')}
                            type="date"
                            dir="ltr"
                            value={form.data.expected_on}
                            onChange={(e) =>
                                form.setData('expected_on', e.target.value)
                            }
                            optional
                            error={errors.expected_on}
                        />
                        <SelectField
                            label={t('procurement.responsible')}
                            value={form.data.responsible_id}
                            onChange={(e) =>
                                form.setData('responsible_id', e.target.value)
                            }
                            options={[
                                { value: '', label: '—' },
                                ...members.map((m) => ({
                                    value: String(m.id),
                                    label: m.name,
                                })),
                            ]}
                            optional
                            error={errors.responsible_id}
                        />
                    </div>
                    <TextareaField
                        label={t('procurement.notes')}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        optional
                        error={errors.notes}
                    />
                </Card>

                <Card className="gap-4">
                    <h2 className="text-heading text-strong">
                        {t('procurement.items')}
                    </h2>
                    <ItemSearch onPick={addItem} />
                    {errors.lines && (
                        <p
                            className="text-caption text-danger-text"
                            role="alert"
                        >
                            {errors.lines}
                        </p>
                    )}
                    {lines.length === 0 ? (
                        <p className="text-body text-weak">
                            {t('procurement.items_empty')}
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {lines.map((line, index) => (
                                <li
                                    key={`${line.id ?? 'new'}-${line.item_type}-${line.item_id}-${index}`}
                                    data-test="line-row"
                                    className={`grid gap-3 rounded-md border border-stroke-weak p-3 md:items-start ${canCost ? 'md:grid-cols-[1fr_10rem_10rem_auto]' : 'md:grid-cols-[1fr_10rem_auto]'}`}
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold text-strong">
                                            {line.name}
                                        </p>
                                        <p className="text-caption text-weak">
                                            {t('procurement.line_no', {
                                                n: index + 1,
                                            })}
                                        </p>
                                    </div>
                                    <TextField
                                        label={
                                            line.unit
                                                ? `${t('procurement.col_quantity')} (${unitLabel(line.unit, t)})`
                                                : t('procurement.col_quantity')
                                        }
                                        inputMode="decimal"
                                        dir="ltr"
                                        value={line.quantity}
                                        onChange={(e) =>
                                            setLine(index, {
                                                quantity: e.target.value,
                                            })
                                        }
                                        required
                                        error={
                                            errors[`lines.${index}.quantity`]
                                        }
                                    />
                                    {canCost && (
                                        <TextField
                                            label={t('procurement.unit_cost')}
                                            inputMode="decimal"
                                            dir="ltr"
                                            value={line.unit_cost}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    unit_cost: e.target.value,
                                                })
                                            }
                                            optional
                                            error={
                                                errors[
                                                    `lines.${index}.unit_cost`
                                                ]
                                            }
                                        />
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="md:mt-7"
                                        aria-label={t(
                                            'procurement.remove_line',
                                        )}
                                        onClick={() =>
                                            form.setData(
                                                'lines',
                                                lines.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Icon icon={Trash2} />
                                    </Button>
                                    {(errors[`lines.${index}.item_id`] ||
                                        errors[`lines.${index}.item_type`]) && (
                                        <p className="text-caption text-danger-text md:col-span-full">
                                            {errors[`lines.${index}.item_id`] ??
                                                errors[
                                                    `lines.${index}.item_type`
                                                ]}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {canCost && lines.length > 0 && (
                        <div
                            className="flex flex-wrap items-baseline justify-between gap-2 border-t border-stroke-weak pt-3"
                            data-test="totals-preview"
                        >
                            <span className="text-weak">
                                {t('procurement.subtotal_preview')}
                            </span>
                            <Amount value={subtotal / 100} strong />
                            <p className="w-full text-caption text-weak">
                                {t('procurement.subtotal_hint')}
                            </p>
                        </div>
                    )}
                </Card>

                <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.processing}
                        data-test="save-draft"
                    >
                        {t('procurement.save_draft')}
                    </Button>
                    {purchase && purchase.can.confirm && (
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing || form.isDirty}
                            title={
                                form.isDirty
                                    ? t('procurement.confirm_save_first')
                                    : undefined
                            }
                            onClick={confirmDraft}
                            data-test="confirm-draft"
                        >
                            {t('procurement.confirm')}
                        </Button>
                    )}
                    <Button asChild variant="secondary">
                        <Link
                            href={
                                purchase
                                    ? PurchaseController.show.url({
                                          purchase: purchase.id,
                                      })
                                    : PurchaseController.index.url()
                            }
                        >
                            {t('catalog.cancel')}
                        </Link>
                    </Button>
                </div>
                {purchase && form.isDirty && (
                    <p className="text-caption text-weak">
                        {t('procurement.confirm_save_first')}
                    </p>
                )}
            </form>
        </>
    );
}

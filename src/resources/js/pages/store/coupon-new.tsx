import { Head, Link, useForm } from '@inertiajs/react';
import { SelectField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type Variant = {
    id: number;
    name: string;
    price_minor: number;
    available: number;
};

/** CouponNew (SAL-06, P-91): code, title, percent or amount before VAT, dates, scope, minimum and caps. */
export default function CouponNew({ variants }: { variants: Variant[] }) {
    const t = useT();
    const form = useForm({
        code: '',
        title_ar: '',
        title_en: '',
        kind: 'percent',
        value: '',
        starts_at: new Date().toISOString().slice(0, 16),
        ends_at: '',
        variant_ids: [] as number[],
        min_subtotal: '',
        max_uses: '',
        max_uses_per_customer: '',
    });

    return (
        <>
            <Head title={t('coupons.new')} />
            <PageHeader
                title={t('coupons.new')}
                description={t('coupons.new_description')}
                back={
                    <Link href="/store-admin/coupons" className="text-link">
                        {t('coupons.title')}
                    </Link>
                }
            />
            <form
                className="grid max-w-2xl gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({
                        ...d,
                        ends_at: d.ends_at === '' ? null : d.ends_at,
                        max_uses: d.max_uses === '' ? null : Number(d.max_uses),
                        max_uses_per_customer:
                            d.max_uses_per_customer === ''
                                ? null
                                : Number(d.max_uses_per_customer),
                    }));
                    form.post('/store-admin/coupons');
                }}
            >
                <TextField
                    label={t('coupons.code')}
                    hint={t('coupons.code_hint')}
                    dir="ltr"
                    value={form.data.code}
                    onChange={(e) =>
                        form.setData('code', e.target.value.toUpperCase())
                    }
                    error={form.errors.code}
                    required
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t('coupons.title_ar')}
                        value={form.data.title_ar}
                        onChange={(e) =>
                            form.setData('title_ar', e.target.value)
                        }
                        error={form.errors.title_ar}
                        required
                    />
                    <TextField
                        label={t('coupons.title_en')}
                        dir="ltr"
                        value={form.data.title_en}
                        onChange={(e) =>
                            form.setData('title_en', e.target.value)
                        }
                        error={form.errors.title_en}
                        optional
                    />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label={t('coupons.kind')}
                        value={form.data.kind}
                        options={[
                            {
                                value: 'percent',
                                label: t('coupons.kind_percent'),
                            },
                            {
                                value: 'amount',
                                label: t('coupons.kind_amount'),
                            },
                        ]}
                        onChange={(e) => form.setData('kind', e.target.value)}
                    />
                    <TextField
                        label={t('coupons.value')}
                        hint={t('coupons.value_hint')}
                        inputMode="decimal"
                        dir="ltr"
                        value={form.data.value}
                        onChange={(e) => form.setData('value', e.target.value)}
                        error={form.errors.value}
                        required
                    />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t('coupons.starts_at')}
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(e) =>
                            form.setData('starts_at', e.target.value)
                        }
                        error={form.errors.starts_at}
                        required
                    />
                    <TextField
                        label={t('coupons.ends_at')}
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(e) =>
                            form.setData('ends_at', e.target.value)
                        }
                        error={form.errors.ends_at}
                        optional
                    />
                </div>
                <div className="grid gap-4 sm:grid-cols-3">
                    <TextField
                        label={t('coupons.min_subtotal')}
                        inputMode="decimal"
                        dir="ltr"
                        value={form.data.min_subtotal}
                        onChange={(e) =>
                            form.setData('min_subtotal', e.target.value)
                        }
                        error={form.errors.min_subtotal}
                        optional
                    />
                    <TextField
                        label={t('coupons.max_uses')}
                        type="number"
                        min={1}
                        value={form.data.max_uses}
                        onChange={(e) =>
                            form.setData('max_uses', e.target.value)
                        }
                        error={form.errors.max_uses}
                        optional
                    />
                    <TextField
                        label={t('coupons.max_uses_per_customer')}
                        type="number"
                        min={1}
                        value={form.data.max_uses_per_customer}
                        onChange={(e) =>
                            form.setData(
                                'max_uses_per_customer',
                                e.target.value,
                            )
                        }
                        error={form.errors.max_uses_per_customer}
                        optional
                    />
                </div>
                <fieldset className="grid gap-2">
                    <legend className="text-label font-medium">
                        {t('coupons.scope')}
                    </legend>
                    <p className="text-caption text-weak">
                        {t('coupons.scope_hint')}
                    </p>
                    <div className="grid max-h-56 gap-1 overflow-y-auto rounded-md border border-stroke-weak p-2">
                        {variants.map((v) => (
                            <label
                                key={v.id}
                                className="flex min-h-9 items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    checked={form.data.variant_ids.includes(
                                        v.id,
                                    )}
                                    onChange={(e) =>
                                        form.setData(
                                            'variant_ids',
                                            e.target.checked
                                                ? [
                                                      ...form.data.variant_ids,
                                                      v.id,
                                                  ]
                                                : form.data.variant_ids.filter(
                                                      (id) => id !== v.id,
                                                  ),
                                        )
                                    }
                                />
                                <span>{v.name}</span>
                            </label>
                        ))}
                    </div>
                    {form.errors.variant_ids && (
                        <p className="text-caption text-danger-text">
                            {form.errors.variant_ids}
                        </p>
                    )}
                </fieldset>
                <div>
                    <Button type="submit" disabled={form.processing}>
                        {t('coupons.save')}
                    </Button>
                </div>
            </form>
        </>
    );
}

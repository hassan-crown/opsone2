import { Head, Link, useForm } from '@inertiajs/react';
import SupplierController from '@/actions/App/Http/Controllers/Procurement/SupplierController';
import { TextField, TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import {
    BackLink,
    FormErrors,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { SupplierRow } from '@/types/procurement';

type Props = { supplier: SupplierRow | null };

export default function SupplierForm({ supplier }: Props) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const form = useForm({
        name: { ar: supplier?.name.ar ?? '', en: supplier?.name.en ?? '' },
        contact_name: supplier?.contact_name ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        vat_number: supplier?.vat_number ?? '',
        supplies: supplier?.supplies ?? '',
        notes: supplier?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            idempotency_key: key,
            ...(supplier ? { lock_version: supplier.lock_version } : {}),
        }));
        const options = { preserveScroll: true, onError: renew };
        if (supplier) {
            form.put(
                SupplierController.update.url({ supplier: supplier.id }),
                options,
            );
        } else {
            form.post(SupplierController.store.url(), options);
        }
    };

    return (
        <>
            <Head
                title={t(
                    supplier ? 'suppliers.edit_title' : 'suppliers.new_title',
                )}
            />
            <PageHeader
                title={t(
                    supplier ? 'suppliers.edit_title' : 'suppliers.new_title',
                )}
                description={t('suppliers.form_description')}
                back={
                    <BackLink
                        href={SupplierController.index.url()}
                        label={t('suppliers.title')}
                    />
                }
            />
            <form
                onSubmit={submit}
                data-test="supplier-form"
                className="grid gap-4"
            >
                <FormErrors errors={errors} />
                <Card className="gap-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField
                            label={t('suppliers.name_ar')}
                            value={form.data.name.ar}
                            onChange={(e) =>
                                form.setData('name', {
                                    ...form.data.name,
                                    ar: e.target.value,
                                })
                            }
                            lang="ar"
                            dir="rtl"
                            required
                            error={errors['name.ar']}
                        />
                        <TextField
                            label={t('suppliers.name_en')}
                            value={form.data.name.en}
                            onChange={(e) =>
                                form.setData('name', {
                                    ...form.data.name,
                                    en: e.target.value,
                                })
                            }
                            lang="en"
                            dir="ltr"
                            optional
                            error={errors['name.en']}
                        />
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField
                            label={t('suppliers.contact_name')}
                            value={form.data.contact_name}
                            onChange={(e) =>
                                form.setData('contact_name', e.target.value)
                            }
                            optional
                            error={errors.contact_name}
                        />
                        <TextField
                            label={t('suppliers.phone')}
                            type="tel"
                            dir="ltr"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                            optional
                            error={errors.phone}
                        />
                        <TextField
                            label={t('suppliers.email')}
                            type="email"
                            dir="ltr"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            optional
                            error={errors.email}
                        />
                        <TextField
                            label={t('suppliers.vat_number')}
                            hint={t('suppliers.vat_hint')}
                            inputMode="numeric"
                            dir="ltr"
                            value={form.data.vat_number}
                            onChange={(e) =>
                                form.setData('vat_number', e.target.value)
                            }
                            optional
                            error={errors.vat_number}
                        />
                    </div>
                    <TextField
                        label={t('suppliers.supplies')}
                        hint={t('suppliers.supplies_hint')}
                        value={form.data.supplies}
                        onChange={(e) =>
                            form.setData('supplies', e.target.value)
                        }
                        optional
                        error={errors.supplies}
                    />
                    <TextareaField
                        label={t('suppliers.notes')}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        optional
                        error={errors.notes}
                    />
                </Card>
                <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.processing}
                        data-test="save-supplier"
                    >
                        {t('suppliers.save')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={SupplierController.index.url()}>
                            {t('catalog.cancel')}
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

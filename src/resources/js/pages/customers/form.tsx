import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import type { CustomerDetail, Option } from '@/components/customers/types';
import {
    CheckboxField,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = {
    customer: CustomerDetail | null;
    branches: Option[];
    members: Option[];
};

/** Create (CustomerNew / CustomerNewCompany) and edit a customer. Laravel validation is the source of truth. */
export default function CustomerForm({ customer, branches, members }: Props) {
    const t = useT();
    const editing = customer !== null;
    const [kind, setKind] = useState<string>(customer?.kind ?? 'person');
    const [portal, setPortal] = useState(
        customer?.portal_login_enabled ?? false,
    );
    const target = customer
        ? CustomerController.update.form(customer.id)
        : CustomerController.store.form();
    const organization = kind === 'organization';
    const cancelHref = customer
        ? CustomerController.show(customer.id)
        : CustomerController.index();

    return (
        <>
            <Head
                title={
                    editing
                        ? t('customers.form.edit_title')
                        : t('customers.form.new_title')
                }
            />
            <PageHeader
                title={
                    editing
                        ? t('customers.form.edit_title')
                        : t('customers.form.new_title')
                }
            />
            <Form
                {...target}
                className="grid max-w-3xl gap-6"
                data-test="customer-form"
            >
                {({ errors, processing }) => (
                    <>
                        <Card>
                            <SelectField
                                label={t('customers.form.kind')}
                                name="kind"
                                value={kind}
                                onChange={(e) => setKind(e.target.value)}
                                options={[
                                    {
                                        value: 'person',
                                        label: t('customers.kind.person'),
                                    },
                                    {
                                        value: 'organization',
                                        label: t('customers.kind.organization'),
                                    },
                                ]}
                                error={errors.kind}
                                required
                            />
                            <TextField
                                label={t('customers.form.name')}
                                name="name"
                                defaultValue={customer?.name ?? ''}
                                error={errors.name}
                                required
                                autoFocus
                            />
                            <TextField
                                label={t('customers.form.mobile')}
                                hint={t('customers.form.mobile_hint')}
                                name="mobile"
                                type="tel"
                                inputMode="tel"
                                dir="ltr"
                                defaultValue={customer?.mobile ?? ''}
                                error={errors.mobile}
                                required
                            />
                            <TextField
                                label={t('customers.form.email')}
                                hint={t('customers.form.email_hint')}
                                name="email"
                                type="email"
                                dir="ltr"
                                defaultValue={customer?.email ?? ''}
                                error={errors.email}
                                required={portal}
                                optional={!portal}
                            />
                            <input
                                type="hidden"
                                name="portal_login_enabled"
                                value={portal ? '1' : '0'}
                            />
                            <CheckboxField
                                label={t('customers.form.portal_login')}
                                hint={t('customers.form.portal_login_hint')}
                                checked={portal}
                                onChange={(e) => setPortal(e.target.checked)}
                                error={errors.portal_login_enabled}
                            />
                            <input type="hidden" name="country" value="SA" />
                            {editing ? (
                                <p className="text-caption text-weak">
                                    {t('customers.form.branch_fixed')}
                                </p>
                            ) : (
                                <SelectField
                                    label={t('customers.form.branch')}
                                    name="branch_id"
                                    defaultValue={
                                        branches[0]
                                            ? String(branches[0].id)
                                            : ''
                                    }
                                    options={branches.map((b) => ({
                                        value: String(b.id),
                                        label: b.name,
                                    }))}
                                    error={errors.branch_id}
                                    required
                                />
                            )}
                            <SelectField
                                label={t('customers.form.assignee')}
                                name="assignee_id"
                                defaultValue={
                                    customer?.assignee_id
                                        ? String(customer.assignee_id)
                                        : ''
                                }
                                options={[
                                    {
                                        value: '',
                                        label: t('customers.form.no_assignee'),
                                    },
                                    ...members.map((m) => ({
                                        value: String(m.id),
                                        label: m.name,
                                    })),
                                ]}
                                error={errors.assignee_id}
                                optional
                            />
                            <SelectField
                                label={t('customers.form.channel')}
                                name="preferred_channel"
                                defaultValue={customer?.preferred_channel ?? ''}
                                options={[
                                    {
                                        value: '',
                                        label: t('customers.form.no_channel'),
                                    },
                                    {
                                        value: 'whatsapp',
                                        label: t('customers.channel.whatsapp'),
                                    },
                                    {
                                        value: 'phone',
                                        label: t('customers.channel.phone'),
                                    },
                                    {
                                        value: 'email',
                                        label: t('customers.channel.email'),
                                    },
                                ]}
                                error={errors.preferred_channel}
                                optional
                            />
                            <TextareaField
                                label={t('customers.form.notes')}
                                name="internal_notes"
                                defaultValue={customer?.internal_notes ?? ''}
                                error={errors.internal_notes}
                                optional
                            />
                        </Card>
                        {organization && (
                            <Card data-test="business-details">
                                <div>
                                    <h2 className="text-heading text-strong">
                                        {t('customers.form.org_title')}
                                    </h2>
                                    <p className="text-caption text-weak">
                                        {t('customers.form.org_hint')}
                                    </p>
                                </div>
                                <TextField
                                    label={t('customers.form.cr')}
                                    name="commercial_registration"
                                    dir="ltr"
                                    inputMode="numeric"
                                    defaultValue={
                                        customer?.commercial_registration ?? ''
                                    }
                                    error={errors.commercial_registration}
                                    optional
                                />
                                <TextField
                                    label={t('customers.form.vat')}
                                    name="vat_number"
                                    dir="ltr"
                                    inputMode="numeric"
                                    defaultValue={customer?.vat_number ?? ''}
                                    error={errors.vat_number}
                                    optional
                                />
                                <TextField
                                    label={t('customers.form.office_phone')}
                                    name="office_phone"
                                    type="tel"
                                    dir="ltr"
                                    defaultValue={customer?.office_phone ?? ''}
                                    error={errors.office_phone}
                                    optional
                                />
                            </Card>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                loading={processing}
                                data-test="customer-save"
                            >
                                {t('customers.form.save')}
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href={cancelHref}>
                                    {t('customers.form.cancel')}
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

CustomerForm.layout = {
    breadcrumbs: [{ title: 'customers.nav', href: CustomerController.index() }],
};

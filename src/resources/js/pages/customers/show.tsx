import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import CustomerContactController from '@/actions/App/Http/Controllers/Customers/CustomerContactController';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import PortalInviteController from '@/actions/App/Http/Controllers/Customers/PortalInviteController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { ContactDialog } from '@/components/customers/contact-dialog';
import type {
    Contact,
    CustomerDetail,
    InterestRow,
} from '@/components/customers/types';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { RowMenu } from '@/components/org/row-menu';
import { Alert, AlertBody } from '@/components/ui/alert';
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
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    customer: CustomerDetail;
    sensitiveHidden: boolean;
    contacts: Contact[];
    interests: InterestRow[];
    can: { edit: boolean };
};

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap justify-between gap-2 border-b border-stroke-weak py-2 last:border-0">
            <dt className="text-weak">{label}</dt>
            <dd className="font-semibold text-strong">{children}</dd>
        </div>
    );
}

/** CustomerProfile / CustomerCompany. Sensitive blocks render only when the server sent them. */
export default function CustomerShow({
    customer,
    sensitiveHidden,
    contacts,
    interests,
    can,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const { orgNav } = usePage().props;
    const [editing, setEditing] = useState<Contact | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [removing, setRemoving] = useState<Contact | null>(null);
    const organization = customer.kind === 'organization';
    const hasBusiness =
        customer.commercial_registration ||
        customer.vat_number ||
        customer.office_phone;

    const openForm = (contact: Contact | null) => {
        setEditing(contact);
        setFormOpen(true);
    };

    return (
        <>
            <Head title={customer.name} />
            <PageHeader
                title={customer.name}
                description={
                    customer.created_at
                        ? `${t('customers.col.created')}: ${formatDate(customer.created_at, locale)}`
                        : undefined
                }
                actions={
                    <>
                        {orgNav?.orders && (
                            <Button
                                asChild
                                variant="secondary"
                                data-test="new-order"
                            >
                                <Link
                                    href={OrderController.create({
                                        query: { customer: customer.id },
                                    })}
                                >
                                    {t('orders.add')}
                                </Link>
                            </Button>
                        )}
                        <Button asChild variant="secondary">
                            <Link
                                href={InterestController.create({
                                    query: { customer_id: customer.id },
                                })}
                            >
                                {t('customers.profile.new_interest')}
                            </Link>
                        </Button>
                        {can.edit && customer.portal_login_enabled && (
                            <Button
                                variant="secondary"
                                data-test="portal-invite"
                                onClick={() =>
                                    router.post(
                                        PortalInviteController.url(customer.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('portal.invite.action')}
                            </Button>
                        )}
                        {can.edit && (
                            <Button asChild data-test="edit-customer">
                                <Link
                                    href={CustomerController.edit(customer.id)}
                                >
                                    <Icon icon={Pencil} />
                                    {t('customers.profile.edit')}
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />
            {sensitiveHidden && (
                <Alert
                    variant="info"
                    className="mb-4"
                    data-test="hidden-notice"
                >
                    <AlertBody>{t('customers.profile.hidden')}</AlertBody>
                </Alert>
            )}
            {errors.contact && (
                <Alert variant="danger" className="mb-4">
                    <AlertBody>{errors.contact}</AlertBody>
                </Alert>
            )}
            <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <div className="grid content-start gap-4">
                    {organization && (
                        <Card data-test="contacts-card">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h2 className="text-heading text-strong">
                                        {t('customers.contacts.title')}
                                    </h2>
                                    <p className="text-caption text-weak">
                                        {t('customers.contacts.desc')}
                                    </p>
                                </div>
                                {can.edit && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => openForm(null)}
                                        data-test="add-contact"
                                    >
                                        <Icon icon={Plus} />
                                        {t('customers.contacts.add')}
                                    </Button>
                                )}
                            </div>
                            {contacts.length === 0 ? (
                                <p className="text-weak">
                                    {t('customers.contacts.empty')}
                                </p>
                            ) : (
                                <ul className="grid gap-3">
                                    {contacts.map((c) => (
                                        <li
                                            key={c.id}
                                            className="flex items-start justify-between gap-3 rounded-md border border-stroke-weak p-3"
                                            data-test="contact-row"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-semibold text-strong">
                                                    {c.name}{' '}
                                                    {c.is_primary && (
                                                        <Badge variant="success">
                                                            {t(
                                                                'customers.contacts.primary',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </p>
                                                {c.job_title && (
                                                    <p className="text-weak">
                                                        {c.job_title}
                                                    </p>
                                                )}
                                                <p
                                                    dir="ltr"
                                                    className="text-start text-body"
                                                >
                                                    {c.mobile}
                                                    {c.email
                                                        ? ` · ${c.email}`
                                                        : ''}
                                                </p>
                                                {c.note && (
                                                    <p className="text-caption text-weak">
                                                        {c.note}
                                                    </p>
                                                )}
                                            </div>
                                            {can.edit && (
                                                <RowMenu
                                                    label={t(
                                                        'customers.contacts.row_actions',
                                                    )}
                                                    items={[
                                                        ...(c.is_primary
                                                            ? []
                                                            : [
                                                                  {
                                                                      label: t(
                                                                          'customers.contacts.make_primary',
                                                                      ),
                                                                      icon: Check,
                                                                      onSelect:
                                                                          () =>
                                                                              router.post(
                                                                                  CustomerContactController.primary.url(
                                                                                      c.id,
                                                                                  ),
                                                                              ),
                                                                  },
                                                              ]),
                                                        {
                                                            label: t(
                                                                'customers.contacts.edit',
                                                            ),
                                                            icon: Pencil,
                                                            onSelect: () =>
                                                                openForm(c),
                                                        },
                                                        {
                                                            label: t(
                                                                'customers.contacts.remove',
                                                            ),
                                                            icon: Trash2,
                                                            danger: true,
                                                            separatorBefore: true,
                                                            onSelect: () =>
                                                                setRemoving(c),
                                                        },
                                                    ]}
                                                />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                    <Card data-test="interests-card">
                        <h2 className="text-heading text-strong">
                            {t('customers.profile.interests')}
                        </h2>
                        {interests.length === 0 ? (
                            <p className="text-weak">
                                {t('customers.profile.no_interests')}
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {interests.map((i) => (
                                    <li
                                        key={i.id}
                                        className="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <Link
                                            href={InterestController.show(i.id)}
                                            className="text-link hover:underline"
                                        >
                                            <span dir="ltr">{i.reference}</span>{' '}
                                            — {i.need}
                                        </Link>
                                        <Badge
                                            variant={
                                                i.status === 'converted'
                                                    ? 'success'
                                                    : i.status === 'new'
                                                      ? 'info'
                                                      : 'neutral'
                                            }
                                        >
                                            {t(
                                                `interests.status.${i.status}` as TranslationKey,
                                            )}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
                <div className="grid content-start gap-4">
                    <Card>
                        <h2 className="text-heading text-strong">
                            {t('customers.profile.contact_info')}
                        </h2>
                        <dl>
                            <Fact label={t('customers.col.mobile')}>
                                <span dir="ltr">{customer.mobile}</span>
                            </Fact>
                            <Fact label={t('customers.col.email')}>
                                <span dir="ltr">{customer.email ?? '—'}</span>
                            </Fact>
                            <Fact label={t('customers.col.branch')}>
                                {customer.branch ?? '—'}
                            </Fact>
                            <Fact label={t('customers.profile.owner')}>
                                {customer.assignee ??
                                    t('customers.profile.no_owner')}
                            </Fact>
                            {customer.preferred_channel && (
                                <Fact label={t('customers.form.channel')}>
                                    {t(
                                        `customers.channel.${customer.preferred_channel}` as TranslationKey,
                                    )}
                                </Fact>
                            )}
                            {customer.portal_login_enabled && (
                                <Fact label={t('customers.profile.portal_on')}>
                                    ✓
                                </Fact>
                            )}
                        </dl>
                    </Card>
                    {organization && hasBusiness && (
                        <Card data-test="business-card">
                            <h2 className="text-heading text-strong">
                                {t('customers.profile.business')}
                            </h2>
                            <dl>
                                {customer.commercial_registration && (
                                    <Fact label={t('customers.form.cr')}>
                                        <span dir="ltr">
                                            {customer.commercial_registration}
                                        </span>
                                    </Fact>
                                )}
                                {customer.vat_number && (
                                    <Fact label={t('customers.form.vat')}>
                                        <span dir="ltr">
                                            {customer.vat_number}
                                        </span>
                                    </Fact>
                                )}
                                {customer.office_phone && (
                                    <Fact
                                        label={t('customers.form.office_phone')}
                                    >
                                        <span dir="ltr">
                                            {customer.office_phone}
                                        </span>
                                    </Fact>
                                )}
                            </dl>
                        </Card>
                    )}
                    {customer.internal_notes && (
                        <Card data-test="notes-card">
                            <h2 className="text-heading text-strong">
                                {t('customers.profile.notes')}
                            </h2>
                            <p className="whitespace-pre-line">
                                {customer.internal_notes}
                            </p>
                        </Card>
                    )}
                </div>
            </div>
            <ContactDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                customerId={customer.id}
                contact={editing}
            />
            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <DialogContent size="sm" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('customers.contacts.remove_title', {
                                name: removing?.name ?? '',
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('customers.contacts.remove_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setRemoving(null)}
                        >
                            {t('customers.form.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                if (removing) {
                                    router.delete(
                                        CustomerContactController.destroy.url(
                                            removing.id,
                                        ),
                                        {
                                            onFinish: () => setRemoving(null),
                                        },
                                    );
                                }
                            }}
                            data-test="contact-remove-confirm"
                        >
                            {t('customers.contacts.remove')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'customers.nav', href: CustomerController.index() }],
};

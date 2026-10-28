import { Form } from '@inertiajs/react';
import CustomerContactController from '@/actions/App/Http/Controllers/Customers/CustomerContactController';
import { CheckboxField, TextField } from '@/components/opsone/field';
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
import type { Contact } from './types';

/** Add or edit a contact person of an organization. */
export function ContactDialog({
    open,
    onOpenChange,
    customerId,
    contact,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    customerId: number;
    contact: Contact | null;
}) {
    const t = useT();
    const target = contact
        ? CustomerContactController.update.form(contact.id)
        : CustomerContactController.store.form(customerId);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {contact
                            ? t('customers.contacts.edit')
                            : t('customers.contacts.add')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('customers.contacts.desc')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...target}
                    key={contact?.id ?? 'new'}
                    className="grid gap-4"
                    data-test="contact-form"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <TextField
                                label={t('customers.contacts.name')}
                                name="name"
                                defaultValue={contact?.name ?? ''}
                                error={errors.name}
                                required
                                autoFocus
                            />
                            <TextField
                                label={t('customers.contacts.mobile')}
                                name="mobile"
                                type="tel"
                                dir="ltr"
                                defaultValue={contact?.mobile ?? ''}
                                error={errors.mobile}
                                required
                            />
                            <TextField
                                label={t('customers.contacts.job_title')}
                                name="job_title"
                                defaultValue={contact?.job_title ?? ''}
                                error={errors.job_title}
                                optional
                            />
                            <TextField
                                label={t('customers.contacts.email')}
                                name="email"
                                type="email"
                                dir="ltr"
                                defaultValue={contact?.email ?? ''}
                                error={errors.email}
                                optional
                            />
                            <TextField
                                label={t('customers.contacts.note')}
                                name="note"
                                defaultValue={contact?.note ?? ''}
                                error={errors.note}
                                optional
                            />
                            {errors.contact && (
                                <p
                                    role="alert"
                                    className="text-caption text-danger-text"
                                >
                                    {errors.contact}
                                </p>
                            )}
                            {!contact && (
                                <CheckboxField
                                    label={t('customers.contacts.as_primary')}
                                    name="is_primary"
                                    value="1"
                                />
                            )}
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('customers.form.cancel')}
                                </Button>
                                <Button
                                    loading={processing}
                                    data-test="contact-save"
                                >
                                    {t('customers.contacts.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

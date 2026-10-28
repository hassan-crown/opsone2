import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { index, store } from '@/routes/customer/tickets';
import type { PortalOrder } from '@/types/portal';
import type { TicketType } from '@/types/tickets';

type Props = {
    orders: PortalOrder[];
    order_id: number | null;
    max_files: number;
};

const TYPES: TicketType[] = ['issue', 'question', 'request', 'other'];

/** TicketNew (P-71): a ticket linked to one of the customer's orders, or a general question without one. */
export default function PortalTicketNew({
    orders,
    order_id,
    max_files,
}: Props) {
    const t = useT();
    // One key per form instance: a double submit opens one ticket.
    const [clientKey] = useState(() => crypto.randomUUID());

    return (
        <>
            <Head title={t('tickets.new')} />
            <Link
                href={index().url}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                {t('tickets.back')}
            </Link>
            <h1 className="text-title text-strong">{t('tickets.new')}</h1>
            <p className="mt-1 mb-6 text-body text-weak">
                {t('tickets.new_lead')}
            </p>
            <Card>
                <Form
                    {...store.form()}
                    className="flex flex-col gap-4"
                    encType="multipart/form-data"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="client_key"
                                value={clientKey}
                            />
                            <SelectField
                                label={t('tickets.field.type')}
                                name="type"
                                required
                                defaultValue="question"
                                options={TYPES.map((type) => ({
                                    value: type,
                                    label: t(
                                        `tickets.type.${type}` as TranslationKey,
                                    ),
                                }))}
                                error={errors.type}
                            />
                            <SelectField
                                label={t('tickets.field.order')}
                                name="order_id"
                                defaultValue={order_id ? String(order_id) : ''}
                                options={[
                                    {
                                        value: '',
                                        label: t('tickets.field.no_order'),
                                    },
                                    ...orders.map((order) => ({
                                        value: String(order.id),
                                        label: [order.number, order.title]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })),
                                ]}
                                error={errors.order_id}
                            />
                            <TextField
                                label={t('tickets.field.subject')}
                                name="subject"
                                required
                                maxLength={160}
                                error={errors.subject}
                            />
                            <TextareaField
                                label={t('tickets.field.body')}
                                name="body"
                                required
                                maxLength={5000}
                                rows={6}
                                error={errors.body}
                            />
                            <TextField
                                label={t('tickets.field.files')}
                                hint={t('tickets.field.files_hint', {
                                    max: max_files,
                                })}
                                name="files[]"
                                type="file"
                                multiple
                                optional
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                error={errors.files ?? errors['files.0']}
                            />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('tickets.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </Card>
        </>
    );
}

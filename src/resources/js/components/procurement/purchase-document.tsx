import { router, useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import PurchaseDocumentController from '@/actions/App/Http/Controllers/Procurement/PurchaseDocumentController';
import { Icon } from '@/components/opsone/icon';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { FormErrors, useIdempotencyKey } from '@/components/procurement/shared';
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
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { PurchaseDetail, PurchaseSend } from '@/types/procurement';

/**
 * Email the purchase order to its supplier (ADR-0038): the facility's subject and body, editable here, with the PDF
 * attached. The send is queued in the outbox; its status shows in the send log below.
 */
export function EmailDialog({
    purchase,
    open,
    onOpenChange,
}: {
    purchase: PurchaseDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const defaults = purchase.email_defaults;
    const form = useForm({
        recipient: defaults?.recipient ?? '',
        subject: defaults?.subject ?? '',
        body: defaults?.body ?? '',
        locale: 'ar',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, idempotency_key: key }));
        form.post(
            PurchaseDocumentController.email.url({ purchase: purchase.id }),
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: renew,
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="lg" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {t('purchase_document.email_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('purchase_document.email_desc')}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="grid gap-4"
                    data-test="email-form"
                >
                    <FormErrors errors={errors} />
                    <TextField
                        label={t('purchase_document.recipient')}
                        type="email"
                        dir="ltr"
                        value={form.data.recipient}
                        onChange={(e) =>
                            form.setData('recipient', e.target.value)
                        }
                        error={errors.recipient}
                        required
                    />
                    <SelectField
                        label={t('purchase_document.language')}
                        value={form.data.locale}
                        onChange={(e) => form.setData('locale', e.target.value)}
                        options={[
                            {
                                value: 'ar',
                                label: t('purchase_document.language_ar'),
                            },
                            {
                                value: 'en',
                                label: t('purchase_document.language_en'),
                            },
                        ]}
                    />
                    <TextField
                        label={t('purchase_document.subject')}
                        value={form.data.subject}
                        onChange={(e) =>
                            form.setData('subject', e.target.value)
                        }
                        error={errors.subject}
                    />
                    <TextareaField
                        label={t('purchase_document.body')}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        error={errors.body}
                        rows={6}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('catalog.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            loading={form.processing}
                            disabled={form.processing}
                            data-test="send-email"
                        >
                            {t('purchase_document.send')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const SEND_VARIANT = {
    queued: 'info',
    sent: 'success',
    failed: 'danger',
} as const;

/** The send log: every emailed copy with its delivery status; a failed one may be sent again. */
export function SendsCard({
    purchase,
    sends,
}: {
    purchase: PurchaseDetail;
    sends: PurchaseSend[];
}) {
    const t = useT();
    const { locale } = useLocale();
    const { key, renew } = useIdempotencyKey();

    if (sends.length === 0) {
        return null;
    }

    const retry = (send: PurchaseSend) =>
        router.post(
            PurchaseDocumentController.retry.url({
                purchase: purchase.id,
                send: send.id,
            }),
            { idempotency_key: key },
            { preserveScroll: true, onFinish: renew },
        );

    return (
        <Card className="gap-0 p-0 md:p-0">
            <TableWrap>
                <Table caption={t('purchase_document.sends_title')}>
                    <thead>
                        <tr>
                            <Th>{t('purchase_document.sent_on')}</Th>
                            <Th>{t('purchase_document.recipient')}</Th>
                            <Th>{t('purchase_document.subject')}</Th>
                            <Th>{t('purchase_document.status_col')}</Th>
                            <Th>{t('purchase_document.version_col')}</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        {sends.map((send) => (
                            <tr key={send.id} data-test="send-row">
                                <Td>
                                    {formatDateTime(
                                        send.sent_at ?? send.created_at,
                                        locale,
                                    )}
                                    {send.sender && (
                                        <span className="block text-caption text-weak">
                                            {send.sender}
                                        </span>
                                    )}
                                </Td>
                                <Td>
                                    <bdi dir="ltr">{send.recipient}</bdi>
                                </Td>
                                <Td>{send.subject}</Td>
                                <Td>
                                    <Badge variant={SEND_VARIANT[send.status]}>
                                        {t(
                                            `purchase_document.status.${send.retrying ? 'retrying' : send.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </Td>
                                <Td className="tabular-nums">
                                    v{send.template_version}
                                </Td>
                                <Td className="text-end">
                                    {send.status === 'failed' &&
                                        purchase.can.email && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() => retry(send)}
                                            >
                                                <Icon icon={RotateCcw} />
                                                {t('purchase_document.retry')}
                                            </Button>
                                        )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </TableWrap>
        </Card>
    );
}

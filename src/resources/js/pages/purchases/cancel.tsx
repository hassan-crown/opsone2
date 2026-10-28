import { Head, Link, useForm } from '@inertiajs/react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import { TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import {
    BackLink,
    FormErrors,
    PurchaseStatusBadge,
    Qty,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { PurchaseDetail } from '@/types/procurement';

export default function PurchaseCancel({
    purchase,
}: {
    purchase: PurchaseDetail;
}) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const form = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const showUrl = PurchaseController.show.url({ purchase: purchase.id });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            reason: data.reason.trim(),
            idempotency_key: key,
            lock_version: purchase.lock_version,
        }));
        form.post(PurchaseController.cancel.url({ purchase: purchase.id }), {
            preserveScroll: true,
            onError: renew,
        });
    };

    return (
        <>
            <Head
                title={t('procurement.cancel_title', {
                    number: purchase.number,
                })}
            />
            <PageHeader
                title={t('procurement.cancel_title', {
                    number: purchase.number,
                })}
                description={purchase.supplier.name.label}
                back={<BackLink href={showUrl} label={purchase.number} />}
                actions={<PurchaseStatusBadge status={purchase.status} />}
            />
            <form
                onSubmit={submit}
                data-test="cancel-form"
                className="grid gap-4"
            >
                <FormErrors errors={errors} />
                <Alert variant="warning">
                    <AlertBody>
                        <p className="font-semibold">
                            {t('procurement.cancel_impact_title')}
                        </p>
                        <p>{t('procurement.cancel_impact')}</p>
                    </AlertBody>
                </Alert>
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('procurement.lines_caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('procurement.col_item')}</Th>
                                    <Th>{t('procurement.col_ordered')}</Th>
                                    <Th>{t('procurement.cancel_kept')}</Th>
                                    <Th>{t('procurement.cancel_cancelled')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {purchase.lines.map((l) => (
                                    <tr key={l.id} data-test="cancel-line">
                                        <Td className="font-semibold">
                                            {l.name.label}
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.quantity_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.accepted_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                        <Td>
                                            <Qty
                                                milli={l.remaining_milli}
                                                unit={l.unit}
                                            />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
                <Card>
                    <TextareaField
                        label={t('procurement.reason')}
                        hint={t('procurement.cancel_reason_hint')}
                        value={form.data.reason}
                        maxLength={500}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        required
                        error={errors.reason}
                    />
                </Card>
                <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                    <Button
                        type="submit"
                        variant="destructive"
                        loading={form.processing}
                        disabled={form.processing}
                        data-test="confirm-cancel"
                    >
                        {t('procurement.cancel_confirm')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={showUrl}>
                            {t('procurement.cancel_back')}
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

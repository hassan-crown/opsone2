import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import FinanceHomeController from '@/actions/App/Http/Controllers/Finance/FinanceHomeController';
import OrderFinanceController from '@/actions/App/Http/Controllers/Finance/OrderFinanceController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { SettlementMoney } from '@/components/settlement/dialogs';
import type {
    DocumentStatus,
    ExternalDocument,
} from '@/components/settlement/types';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Row = ExternalDocument & {
    order: { id: number; number: string; customer: string };
};

type Props = { documents: Row[] };

const TONE: Record<DocumentStatus, 'warning' | 'info' | 'success' | 'danger'> =
    {
        requested: 'warning',
        attached: 'info',
        accepted: 'success',
        needs_correction: 'danger',
    };

/**
 * FinInvoices (P-44, FIN-10): documents the accounting system still has to issue, and the linked ones with their
 * review state. Invoices are issued outside OpsOne and linked here to the order and its event (BR-20); linking and
 * acceptance never claim compliance. Linking itself happens on the order (FinInvoiceLink).
 */
export default function FinanceInvoices({ documents }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const pending = documents.filter(
        (d) => d.status === 'requested' || d.status === 'needs_correction',
    );
    const linked = documents.filter(
        (d) => d.status === 'attached' || d.status === 'accepted',
    );

    return (
        <>
            <Head title={t('invoices.title')} />
            <PageHeader
                title={t('invoices.title')}
                description={t('invoices.description')}
                actions={
                    <Button asChild variant="secondary">
                        <Link href={FinanceHomeController.index.url()}>
                            {t('finance.home.title')}
                        </Link>
                    </Button>
                }
            />
            <Alert variant="info" className="mb-6">
                <AlertBody>{t('invoices.not_issued_here')}</AlertBody>
            </Alert>
            <div className="grid gap-6">
                <Card data-test="pending-documents">
                    <CardHeader>
                        <CardTitle>{t('invoices.pending.title')}</CardTitle>
                    </CardHeader>
                    {pending.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={t('invoices.pending.empty')}
                        />
                    ) : (
                        <ul className="grid gap-3">
                            {pending.map((d) => (
                                <li
                                    key={d.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-stroke-weak p-3"
                                >
                                    <span className="grid gap-1">
                                        <span className="font-semibold text-strong">
                                            <bdi dir="ltr">
                                                {d.order.number}
                                            </bdi>{' '}
                                            ·{' '}
                                            {t(
                                                `invoices.kind.${d.kind}` as TranslationKey,
                                            )}
                                        </span>
                                        <span className="text-caption text-weak">
                                            {d.order.customer} ·{' '}
                                            {t(
                                                `invoices.event.${d.event}` as TranslationKey,
                                            )}
                                            {d.receipt_number &&
                                                ` · ${d.receipt_number}`}
                                        </span>
                                        {d.correction_reason && (
                                            <span className="text-caption text-danger-text">
                                                {d.correction_reason}
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <Badge variant={TONE[d.status]}>
                                            {t(
                                                `invoices.status.${d.status}` as TranslationKey,
                                            )}
                                        </Badge>
                                        <Button asChild size="sm">
                                            <Link
                                                href={OrderFinanceController.show.url(
                                                    d.order.id,
                                                )}
                                            >
                                                {t('invoices.link.action')}
                                            </Link>
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card data-test="linked-documents">
                    <CardHeader>
                        <CardTitle>{t('invoices.linked.title')}</CardTitle>
                    </CardHeader>
                    {linked.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={t('invoices.linked.empty')}
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-body">
                                <thead className="text-label text-weak">
                                    <tr>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.number')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.kind')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.order')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.event')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.date')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.amount')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('invoices.field.review')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {linked.map((d) => (
                                        <tr
                                            key={d.id}
                                            className="border-t border-stroke-weak"
                                            data-test="linked-document"
                                        >
                                            <td className="py-2">
                                                <bdi dir="ltr">{d.number}</bdi>
                                                {d.files[0]?.url && (
                                                    <a
                                                        href={d.files[0].url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="ms-2 text-caption text-link underline"
                                                    >
                                                        {t('invoices.file')}
                                                    </a>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {t(
                                                    `invoices.kind.${d.kind}` as TranslationKey,
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <Link
                                                    href={OrderFinanceController.show.url(
                                                        d.order.id,
                                                    )}
                                                    className="text-link underline"
                                                >
                                                    <bdi dir="ltr">
                                                        {d.order.number}
                                                    </bdi>
                                                </Link>
                                            </td>
                                            <td className="py-2">
                                                {t(
                                                    `invoices.event.${d.event}` as TranslationKey,
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {d.issued_on
                                                    ? formatDate(
                                                          d.issued_on,
                                                          locale,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="py-2">
                                                <SettlementMoney
                                                    minor={d.amount_minor}
                                                />
                                            </td>
                                            <td className="py-2">
                                                <Badge variant={TONE[d.status]}>
                                                    {t(
                                                        `invoices.status.${d.status}` as TranslationKey,
                                                    )}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}

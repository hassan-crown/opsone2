import EInvoicingController from '@/actions/App/Http/Controllers/EInvoicing/EInvoicingController';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatAmount } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

/** One ZATCA document of the order (ADR-0032), shown to whoever sees the order's money. */
export type OrderEInvoice = {
    id: number;
    number: string;
    kind: 'invoice' | 'credit_note' | 'debit_note';
    type: 'standard' | 'simplified';
    total_minor: number;
    issue_date: string;
    status: string;
};

export function OrderEInvoices({ invoices }: { invoices: OrderEInvoice[] }) {
    const t = useT();
    const { locale } = useLocale();

    if (invoices.length === 0) {
        return null;
    }

    return (
        <Card data-test="order-einvoices">
            <CardHeader>
                <CardTitle>{t('einvoicing.order.title')}</CardTitle>
                <CardDescription>
                    {t('einvoicing.order.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y divide-stroke-weak">
                    {invoices.map((invoice) => (
                        <li
                            key={invoice.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <span className="space-y-0.5">
                                <span className="block font-semibold text-strong">
                                    <span dir="ltr">{invoice.number}</span> ·{' '}
                                    {t(
                                        `einvoicing.kind.${invoice.kind}` as TranslationKey,
                                    )}
                                </span>
                                <span className="block text-caption text-weak">
                                    <span dir="ltr">{invoice.issue_date}</span>{' '}
                                    ·{' '}
                                    {formatAmount(
                                        invoice.total_minor / 100,
                                        locale,
                                    )}
                                </span>
                            </span>
                            <span className="flex items-center gap-2">
                                <Badge variant="neutral">
                                    {t(
                                        `einvoicing.submission.${invoice.status}` as TranslationKey,
                                    )}
                                </Badge>
                                <a
                                    href={EInvoicingController.pdf.url(
                                        invoice.id,
                                    )}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-link"
                                >
                                    PDF
                                </a>
                            </span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

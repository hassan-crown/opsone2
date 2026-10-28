import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    DateText,
    Forward,
    Money,
    Section,
    StatusBadge,
} from '@/components/portal/parts';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { show as showDocument } from '@/routes/customer/documents';
import { show as showOrder } from '@/routes/customer/orders';
import type { PortalDocumentSummary, PortalReceipt } from '@/types/portal';

type Props = { documents: PortalDocumentSummary[]; receipts: PortalReceipt[] };

/** CustDocs (WEB-10): documents by type and date, and receipts. */
export default function PortalDocuments({ documents, receipts }: Props) {
    const t = useT();
    const designs = documents.filter((d) => d.kind === 'design');
    const agreements = documents.filter((d) => d.kind !== 'design');

    const list = (items: PortalDocumentSummary[]) => (
        <div className="flex flex-col gap-2">
            {items.map((doc) => (
                <Link
                    key={doc.id}
                    href={showDocument(doc.id).url}
                    className="flex items-center gap-3 rounded-lg border border-stroke-weak bg-card p-3 hover:bg-hover"
                >
                    <div className="min-w-0 flex-1">
                        <p className="text-label text-strong">
                            {t(
                                `portal.document.kind.${doc.kind}` as TranslationKey,
                            )}{' '}
                            <bdi dir="ltr" className="text-weak">
                                {doc.number}
                            </bdi>
                        </p>
                        <p className="text-caption text-weak">
                            <bdi dir="ltr">{doc.order}</bdi>
                            {t('portal.sep')}
                            <DateText value={doc.sent_on} />
                        </p>
                    </div>
                    <StatusBadge scope="document" status={doc.status} />
                    <Forward />
                </Link>
            ))}
        </div>
    );

    return (
        <>
            <Head title={t('portal.nav.documents')} />
            <h1 className="text-title text-strong">
                {t('portal.nav.documents')}
            </h1>
            <p className="mt-1 mb-6 text-body text-weak">
                {t('portal.documents.lead')}
            </p>

            {documents.length === 0 && receipts.length === 0 && (
                <EmptyState
                    icon={FileText}
                    title={t('portal.documents.empty')}
                />
            )}
            {designs.length > 0 && (
                <Section title={t('portal.documents.designs')}>
                    {list(designs)}
                </Section>
            )}
            {agreements.length > 0 && (
                <Section title={t('portal.documents.agreements')}>
                    {list(agreements)}
                </Section>
            )}
            {receipts.length > 0 && (
                <Section title={t('portal.order.receipts')}>
                    <ul className="divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-card">
                        {receipts.map((receipt) => (
                            <li key={receipt.id}>
                                <Link
                                    href={showOrder(receipt.order_id).url}
                                    className="flex items-center justify-between gap-2 p-3 text-body hover:bg-hover"
                                >
                                    <span>
                                        <bdi dir="ltr" className="text-strong">
                                            {receipt.number}
                                        </bdi>
                                        <span className="block text-caption text-weak">
                                            <bdi dir="ltr">{receipt.order}</bdi>
                                            {t('portal.sep')}
                                            <DateText
                                                value={receipt.issued_on}
                                            />
                                        </span>
                                    </span>
                                    <Money minor={receipt.amount_minor} />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </>
    );
}

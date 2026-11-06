import { Form, Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { FieldError, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { FileGallery } from '@/components/portal/file-gallery';
import {
    ActionBar,
    DateText,
    Money,
    Section,
    StatusBadge,
} from '@/components/portal/parts';
import { SignPanel } from '@/components/portal/sign-panel';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { approve, changes, show } from '@/routes/customer/documents';
import { show as showOrder } from '@/routes/customer/orders';
import { BankTransferCard } from '@/components/portal/bank-transfer';
import type { PortalBankDetails, PortalDocument } from '@/types/portal';

// An amount a clause template inserted as plain digits, next to its currency: «(50315.20 ر.س)», «SAR 50315.20».
const CLAUSE_AMOUNT =
    /(SAR\s?)?(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)(\s?(?:ر\.س|ريال|SAR))?/g;
const GROUPED = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * A clause or term as written, with each amount beside a currency grouped by thousands and isolated left-to-right, so
 * the parentheses around it stay in place in Arabic. Other numbers (days, order numbers) are left as they are.
 */
function ClauseText({ text }: { text: string }) {
    const parts: ReactNode[] = [];
    let last = 0;
    for (const match of text.matchAll(CLAUSE_AMOUNT)) {
        const [whole, before, figure, after] = match;
        const index = match.index ?? 0;
        const preceded = index > 0 && /[\w-]/.test(text[index - 1]);
        if ((!before && !after) || preceded) {
            continue;
        }
        parts.push(text.slice(last, index));
        parts.push(
            <span key={index} className="whitespace-nowrap">
                {before}
                <bdi dir="ltr" className="tabular-nums">
                    {GROUPED.format(Number(figure.replace(/,/g, '')))}
                </bdi>
                {after}
            </span>,
        );
        last = index + whole.length;
    }
    parts.push(text.slice(last));

    return <>{parts}</>;
}

/**
 * DocApprove (P-67, WEB-03): the exact content to approve, with its files; approval or a change request travels with
 * the content hash shown here, so a document changed meanwhile is refused instead of approved blindly.
 */
export default function PortalDocumentPage({
    document: doc,
    bank,
}: {
    document: PortalDocument;
    bank: PortalBankDetails | null;
}) {
    const t = useT();
    const [asking, setAsking] = useState(false);
    const c = doc.content;
    const terms = Array.isArray(c.terms) ? c.terms : c.terms ? [c.terms] : [];
    const signing = doc.signing;
    const signable = doc.can.decide && !!signing?.signable;
    const [signOpen, setSigningOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('sign'),
    );

    return (
        <>
            <Head title={doc.number} />
            <Link
                href={showOrder(doc.order_id).url}
                className="mb-2 inline-block text-label text-link underline-offset-4 hover:underline"
            >
                <bdi dir="ltr">{doc.order}</bdi>
            </Link>
            <div className="mb-2 flex flex-wrap items-center gap-2">
                <h1 className="text-title text-strong">
                    {t(`portal.document.kind.${doc.kind}` as TranslationKey)}
                </h1>
                <StatusBadge scope="document" status={doc.status} />
            </div>
            <p className="mb-6 text-body text-weak">
                <bdi dir="ltr">{doc.number}</bdi>
                {doc.sent_on && (
                    <>
                        {t('portal.sep')}
                        {t('portal.document.sent')}{' '}
                        <DateText value={doc.sent_on} />
                    </>
                )}
                {doc.valid_until && (
                    <>
                        {t('portal.sep')}
                        {t('portal.document.valid_until')}{' '}
                        <DateText value={doc.valid_until} />
                    </>
                )}
            </p>

            {doc.replaced_by && (
                <Card className="mb-6">
                    <p className="text-body text-strong">
                        {t('portal.document.replaced')}{' '}
                        <Link
                            href={show(doc.replaced_by.id).url}
                            className="text-link underline"
                        >
                            <bdi dir="ltr">{doc.replaced_by.number}</bdi>
                        </Link>
                    </p>
                </Card>
            )}

            {signing && (
                <div className="mb-6 flex flex-wrap gap-x-4 gap-y-2">
                    {(['ar', 'en'] as const).map((locale) => (
                        <a
                            key={locale}
                            href={signing.pdfs[locale]}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-label text-link underline-offset-4 hover:underline"
                        >
                            <Icon icon={FileText} size="sm" />
                            {t('esign.ui.view_pdf')} ({locale.toUpperCase()})
                        </a>
                    ))}
                </div>
            )}

            {signing?.signed && (
                <Card className="mb-6 border-brand">
                    <p className="text-label font-semibold text-strong">
                        {t('esign.ui.signed_title')}
                    </p>
                    <p className="text-body text-weak">
                        {t('esign.ui.signed_by', {
                            name: signing.signed.signer_name,
                        })}{' '}
                        <DateText value={signing.signed.signed_at} time />
                    </p>
                    <p className="text-caption break-all text-weak" dir="ltr">
                        SHA-256 {signing.signed.pdf_sha256}
                    </p>
                    <div>
                        <Button asChild variant="outline">
                            <a href={signing.signed.download_url}>
                                {t('esign.ui.download_signed')}
                            </a>
                        </Button>
                    </div>
                </Card>
            )}

            {c.description && (
                <Section title={t('portal.document.description')}>
                    <p
                        dir="auto"
                        className="text-body whitespace-pre-line text-strong"
                    >
                        {c.description}
                    </p>
                </Section>
            )}

            {doc.files.length > 0 && (
                <Section title={t('portal.document.files')}>
                    <FileGallery files={doc.files} />
                </Section>
            )}

            {c.lines && c.lines.length > 0 && (
                <Section title={t('portal.document.items')}>
                    <Card className="gap-0 p-0 md:p-0">
                        <ul className="divide-y divide-stroke-weak">
                            {c.lines.map((line, i) => (
                                <li
                                    key={i}
                                    className="flex flex-wrap justify-between gap-2 p-3 text-body"
                                >
                                    <span
                                        dir="auto"
                                        className="min-w-0 flex-1 text-strong"
                                    >
                                        {line.description}
                                    </span>
                                    <span className="shrink-0 whitespace-nowrap text-weak">
                                        <bdi dir="ltr">
                                            {line.quantity} {line.unit ?? ''}
                                        </bdi>
                                    </span>
                                    {line.gross_minor !== undefined &&
                                        line.gross_minor !== null && (
                                            <span className="w-full text-end">
                                                <Money
                                                    minor={line.gross_minor}
                                                />
                                            </span>
                                        )}
                                </li>
                            ))}
                        </ul>
                        {c.totals && (
                            <dl className="grid grid-cols-2 gap-2 border-t border-stroke-weak p-3 text-body">
                                {!!c.totals.discount_minor && (
                                    <>
                                        <dt className="text-weak">
                                            {t('portal.money.discount')}
                                        </dt>
                                        <dd className="text-end">
                                            <Money
                                                minor={c.totals.discount_minor}
                                            />
                                        </dd>
                                    </>
                                )}
                                <dt className="text-weak">
                                    {t('portal.money.vat')}
                                </dt>
                                <dd className="text-end">
                                    <Money minor={c.totals.vat_minor} />
                                </dd>
                                <dt className="text-label text-strong">
                                    {t('portal.money.total')}
                                </dt>
                                <dd className="text-end font-semibold">
                                    <Money minor={c.totals.total_minor} />
                                </dd>
                            </dl>
                        )}
                    </Card>
                </Section>
            )}

            {bank && doc.kind !== 'design' && (
                <Section title={t('portal.bank.section')}>
                    <BankTransferCard
                        bank={bank}
                        amountMinor={c.totals?.total_minor}
                        reference={doc.order}
                    />
                </Section>
            )}

            {(c.execution_days ||
                terms.length > 0 ||
                c.agreement_terms ||
                (c.clauses && c.clauses.length > 0)) && (
                <Section title={t('portal.document.terms')}>
                    <div className="flex flex-col gap-2 text-body text-strong">
                        {c.execution_days ? (
                            <p>
                                {t('portal.document.execution_days', {
                                    days: c.execution_days,
                                })}
                            </p>
                        ) : null}
                        {c.agreement_terms && (
                            <p dir="auto" className="whitespace-pre-line">
                                <ClauseText text={c.agreement_terms} />
                            </p>
                        )}
                        {terms.map((term, i) => (
                            <p
                                key={i}
                                dir="auto"
                                className="whitespace-pre-line"
                            >
                                <ClauseText text={term} />
                            </p>
                        ))}
                        {c.clauses && (
                            <ol className="list-decimal ps-5">
                                {c.clauses.map((clause, i) => (
                                    <li key={i} dir="auto">
                                        <ClauseText text={clause} />
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                </Section>
            )}

            {doc.decision && (
                <Card className="mb-8">
                    <p className="text-body text-strong">
                        {t(
                            `portal.document.decision.${doc.decision.decision}` as TranslationKey,
                        )}{' '}
                        <DateText value={doc.decision.decided_at} time />
                    </p>
                    {doc.decision.note && (
                        <p className="text-body text-weak">
                            {doc.decision.note}
                        </p>
                    )}
                </Card>
            )}

            {signable && signing && !asking && (
                <SignPanel
                    doc={doc}
                    signing={signing}
                    open={signOpen}
                    onOpen={() => setSigningOpen(true)}
                    onRequestChanges={() => setAsking(true)}
                />
            )}

            {doc.can.decide && !signable && !asking && (
                <ActionBar>
                    <p className="text-caption text-weak">
                        {t('portal.document.approve_note')}
                    </p>
                    <Form {...approve.form(doc.id)}>
                        {({ processing, errors }) => (
                            <div className="flex flex-col gap-2">
                                <input
                                    type="hidden"
                                    name="content_hash"
                                    value={doc.content_hash}
                                />
                                {errors.transition && (
                                    <FieldError>{errors.transition}</FieldError>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        className="max-sm:flex-1"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('portal.document.approve')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setAsking(true)}
                                    >
                                        {t('portal.document.request_changes')}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </Form>
                </ActionBar>
            )}

            {doc.can.decide && asking && (
                <Card className="mb-4">
                    <Form
                        {...changes.form(doc.id)}
                        className="flex flex-col gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="content_hash"
                                    value={doc.content_hash}
                                />
                                <TextareaField
                                    label={t('portal.document.changes_note')}
                                    name="note"
                                    required
                                    rows={4}
                                    maxLength={1000}
                                    autoFocus
                                    error={errors.note ?? errors.transition}
                                />
                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('portal.document.send_changes')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setAsking(false)}
                                    >
                                        {t('ui.cancel')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>
            )}
        </>
    );
}

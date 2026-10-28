import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { FieldError, TextareaField } from '@/components/opsone/field';
import {
    DateText,
    FileLinks,
    Money,
    Section,
    StatusBadge,
} from '@/components/portal/parts';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { approve, changes, show } from '@/routes/customer/documents';
import { show as showOrder } from '@/routes/customer/orders';
import type { PortalDocument } from '@/types/portal';

/**
 * DocApprove (P-67, WEB-03): the exact content to approve, with its files; approval or a change request travels with
 * the content hash shown here, so a document changed meanwhile is refused instead of approved blindly.
 */
export default function PortalDocumentPage({
    document: doc,
}: {
    document: PortalDocument;
}) {
    const t = useT();
    const [asking, setAsking] = useState(false);
    const c = doc.content;
    const terms = Array.isArray(c.terms) ? c.terms : c.terms ? [c.terms] : [];

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
                    <FileLinks files={doc.files} />
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
                                    <span className="text-weak">
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
                                {c.agreement_terms}
                            </p>
                        )}
                        {terms.map((term, i) => (
                            <p
                                key={i}
                                dir="auto"
                                className="whitespace-pre-line"
                            >
                                {term}
                            </p>
                        ))}
                        {c.clauses && (
                            <ol className="list-decimal ps-5">
                                {c.clauses.map((clause, i) => (
                                    <li key={i}>{clause}</li>
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

            {doc.can.decide && (
                <Card className="sticky bottom-20 md:bottom-4">
                    <p className="text-body text-weak">
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
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('portal.document.approve')}
                                    </Button>
                                    {!asking && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setAsking(true)}
                                        >
                                            {t(
                                                'portal.document.request_changes',
                                            )}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </Form>
                    {asking && (
                        <Form
                            {...changes.form(doc.id)}
                            className="flex flex-col gap-3 border-t border-stroke-weak pt-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="content_hash"
                                        value={doc.content_hash}
                                    />
                                    <TextareaField
                                        label={t(
                                            'portal.document.changes_note',
                                        )}
                                        name="note"
                                        required
                                        rows={4}
                                        maxLength={1000}
                                        error={errors.note ?? errors.transition}
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
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
                    )}
                </Card>
            )}
        </>
    );
}

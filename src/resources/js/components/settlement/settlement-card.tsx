import { FileText, Gavel, Undo2 } from 'lucide-react';
import { useState } from 'react';
import CorrectionController from '@/actions/App/Http/Controllers/Settlement/CorrectionController';
import DisputeController from '@/actions/App/Http/Controllers/Settlement/DisputeController';
import ExternalDocumentController from '@/actions/App/Http/Controllers/Settlement/ExternalDocumentController';
import RefundController from '@/actions/App/Http/Controllers/Settlement/RefundController';
import { newKey } from '@/components/agreement/money';
import type { FileLink, FinanceDue } from '@/components/finance/types';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import {
    CorrectPaymentDialog,
    DisputeDialog,
    DisputeResolveDialog,
    DocumentLinkDialog,
    RefundExecuteDialog,
    RefundRequestDialog,
    SettlementFieldDialog,
    SettlementMoney,
} from './dialogs';
import type {
    DisputeStatus,
    DocumentStatus,
    RefundStatus,
    SettlementPayload,
    SettlementResponse,
} from './types';
import { useSettlementCall } from './use-settlement-call';

type Variant = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const REFUND_TONE: Record<RefundStatus, Variant> = {
    requested: 'warning',
    in_progress: 'info',
    unresolved: 'warning',
    executed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

const DISPUTE_TONE: Record<DisputeStatus, Variant> = {
    open: 'danger',
    awaiting_external: 'warning',
    resolved: 'success',
};

const DOCUMENT_TONE: Record<DocumentStatus, Variant> = {
    requested: 'warning',
    attached: 'info',
    accepted: 'success',
    needs_correction: 'danger',
};

type Open =
    | { type: 'refund' }
    | { type: 'execute' | 'start' | 'fail' | 'retry' | 'cancel'; id: number }
    | { type: 'correct'; id: number }
    | { type: 'dispute' }
    | { type: 'await' | 'resume' | 'resolve'; id: number }
    | { type: 'link'; id: number | null }
    | { type: 'request_document' }
    | { type: 'document_fix'; id: number }
    | null;

function Files({ files }: { files: FileLink[] }) {
    return (
        <>
            {files.map(
                (file) =>
                    file.url && (
                        <a
                            key={file.uuid}
                            href={file.url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-caption text-link underline"
                        >
                            {file.name}
                        </a>
                    ),
            )}
        </>
    );
}

/**
 * The settlement of the order (P6c, ADR-0015): refundable limits per receipt with correction (FinCorrect), refunds
 * (FinRefund, FinRefundExec), financial disputes (FinDispute, FinDisputeResolve) and external accounting documents
 * (FinInvoiceLink). The server removes amounts without P-37 and decides every action again; buttons follow `can`.
 */
export function SettlementCard({
    orderId,
    orderNumber,
    lockVersion,
    settlement,
    dues,
    today,
    onChange,
}: {
    orderId: number;
    orderNumber: string;
    lockVersion: number;
    settlement: SettlementPayload;
    dues: FinanceDue[];
    today: string;
    onChange: (response: SettlementResponse) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [open, setOpen] = useState<Open>(null);
    const accept = useSettlementCall();
    const { can, payments, refunds, corrections, disputes, documents } =
        settlement;
    const refund = (id: number) => refunds.find((r) => r.id === id);
    const dispute = (id: number) => disputes.find((d) => d.id === id);
    const document = (id: number) => documents.find((d) => d.id === id);
    const close = () => setOpen(null);
    const done = (message?: string) => (response: SettlementResponse) => {
        onChange(response);
        setOpen(null);
        if (message) {
            notify('success', { title: message });
        }
    };
    const acceptDocument = async (id: number, version: number) => {
        const data = await accept.run(
            ExternalDocumentController.accept.url(id),
            { idempotency_key: newKey(), lock_version: version },
        );
        if (data) {
            done(t('invoices.review.accepted'))(data);
        }
    };
    const anyRefundable = payments.some((p) => p.refundable);
    const nothing =
        payments.length === 0 &&
        refunds.length === 0 &&
        disputes.length === 0 &&
        documents.length === 0;

    return (
        <Card data-test="settlement">
            <CardHeader>
                <CardTitle>{t('refunds.section.title')}</CardTitle>
                <CardDescription>
                    {t('refunds.section.description')}
                </CardDescription>
            </CardHeader>
            <div className="mb-4 flex flex-wrap gap-2">
                {can.request_refund && anyRefundable && (
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() => setOpen({ type: 'refund' })}
                        data-test="request-refund"
                    >
                        <Icon icon={Undo2} />
                        {t('refunds.request.action')}
                    </Button>
                )}
                {can.dispute && (
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() => setOpen({ type: 'dispute' })}
                        data-test="open-dispute"
                    >
                        <Icon icon={Gavel} />
                        {t('disputes.open.action')}
                    </Button>
                )}
                {can.documents && (
                    <>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() => setOpen({ type: 'link', id: null })}
                            data-test="link-document"
                        >
                            <Icon icon={FileText} />
                            {t('invoices.link.action')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setOpen({ type: 'request_document' })
                            }
                        >
                            {t('invoices.request.action')}
                        </Button>
                    </>
                )}
            </div>
            {accept.error && (
                <p className="mb-2 text-danger-text">{accept.error}</p>
            )}
            {nothing && (
                <p className="text-weak">{t('refunds.section.empty')}</p>
            )}

            {payments.length > 0 && (
                <section
                    className="mb-4 grid gap-2"
                    data-test="settlement-receipts"
                >
                    <h3 className="text-label text-weak">
                        {t('refunds.section.receipts')}
                    </h3>
                    <ul className="grid gap-2">
                        {payments.map((p) => (
                            <li
                                key={p.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-stroke-weak p-2"
                            >
                                <span className="grid">
                                    <bdi
                                        dir="ltr"
                                        className="font-semibold text-strong"
                                    >
                                        {p.receipt_number ?? '—'}
                                    </bdi>
                                    <span className="text-caption text-weak">
                                        {p.status === 'corrected' ? (
                                            t('refunds.section.corrected')
                                        ) : (
                                            <>
                                                {t('refunds.figure.refundable')}
                                                :{' '}
                                                <SettlementMoney
                                                    minor={p.refundable_minor}
                                                />
                                                {(p.reserved_minor ?? 0) >
                                                    0 && (
                                                    <>
                                                        {' · '}
                                                        {t(
                                                            'refunds.figure.reserved',
                                                        )}
                                                        :{' '}
                                                        <SettlementMoney
                                                            minor={
                                                                p.reserved_minor
                                                            }
                                                        />
                                                    </>
                                                )}
                                            </>
                                        )}
                                    </span>
                                </span>
                                {can.correct && p.correctable && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setOpen({
                                                type: 'correct',
                                                id: p.id,
                                            })
                                        }
                                        data-test="correct-payment"
                                    >
                                        {t('refunds.correct.action')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {refunds.length > 0 && (
                <section className="mb-4 grid gap-2" data-test="refunds">
                    <h3 className="text-label text-weak">
                        {t('refunds.section.refunds')}
                    </h3>
                    <ul className="grid gap-2">
                        {refunds.map((r) => (
                            <li
                                key={r.id}
                                className="grid gap-1 rounded-md border border-stroke-weak p-2"
                                data-test="refund-row"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-semibold text-strong">
                                        <bdi dir="ltr">
                                            {r.number ?? `#${r.id}`}
                                        </bdi>{' '}
                                        <SettlementMoney
                                            minor={r.amount_minor}
                                        />
                                    </span>
                                    <Badge variant={REFUND_TONE[r.status]}>
                                        {t(
                                            `refunds.status.${r.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </div>
                                <span className="text-caption text-weak">
                                    {t('refunds.row.of_receipt', {
                                        receipt: r.receipt_number ?? '—',
                                    })}{' '}
                                    · {formatDate(r.requested_at, locale)}
                                    {r.requested_by && ` · ${r.requested_by}`}
                                </span>
                                <span className="text-caption">{r.reason}</span>
                                {(r.failure_reason || r.cancel_reason) && (
                                    <span className="text-caption text-weak">
                                        {r.failure_reason ?? r.cancel_reason}
                                    </span>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Files files={r.files} />
                                    {r.can.confirm && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'execute',
                                                    id: r.id,
                                                })
                                            }
                                            data-test="execute-refund"
                                        >
                                            {t('refunds.execute.action')}
                                        </Button>
                                    )}
                                    {r.can.start && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'start',
                                                    id: r.id,
                                                })
                                            }
                                        >
                                            {t('refunds.start.action')}
                                        </Button>
                                    )}
                                    {r.can.fail && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'fail',
                                                    id: r.id,
                                                })
                                            }
                                        >
                                            {t('refunds.fail.action')}
                                        </Button>
                                    )}
                                    {r.can.retry && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'retry',
                                                    id: r.id,
                                                })
                                            }
                                        >
                                            {t('refunds.retry.action')}
                                        </Button>
                                    )}
                                    {r.can.cancel && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'cancel',
                                                    id: r.id,
                                                })
                                            }
                                        >
                                            {t('refunds.cancel.action')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {corrections.length > 0 && (
                <section className="mb-4 grid gap-2" data-test="corrections">
                    <h3 className="text-label text-weak">
                        {t('refunds.section.corrections')}
                    </h3>
                    <ul className="grid gap-2">
                        {corrections.map((c) => (
                            <li
                                key={c.id}
                                className="grid gap-1 rounded-md border border-stroke-weak p-2"
                            >
                                <span className="font-semibold text-strong">
                                    <bdi dir="ltr">{c.number}</bdi> ·{' '}
                                    {t(
                                        `refunds.correct.kind_${c.kind}` as TranslationKey,
                                    )}
                                </span>
                                <span className="text-caption text-weak">
                                    {t('refunds.row.corrects', {
                                        receipt: c.receipt_number ?? '—',
                                    })}
                                    {c.replacement_receipt &&
                                        ` → ${c.replacement_receipt}`}
                                    {c.before_minor !== undefined && (
                                        <>
                                            {' · '}
                                            <SettlementMoney
                                                minor={c.before_minor}
                                            />{' '}
                                            →{' '}
                                            <SettlementMoney
                                                minor={c.after_minor}
                                            />
                                        </>
                                    )}
                                </span>
                                <span className="text-caption">{c.reason}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {disputes.length > 0 && (
                <section className="mb-4 grid gap-2" data-test="disputes">
                    <h3 className="text-label text-weak">
                        {t('disputes.section.title')}
                    </h3>
                    <ul className="grid gap-2">
                        {disputes.map((d) => (
                            <li
                                key={d.id}
                                className="grid gap-1 rounded-md border border-stroke-weak p-2"
                                data-test="dispute-row"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-semibold text-strong">
                                        {t(
                                            `disputes.subject.${d.subject}` as TranslationKey,
                                        )}{' '}
                                        <SettlementMoney
                                            minor={d.amount_minor}
                                        />
                                    </span>
                                    <Badge variant={DISPUTE_TONE[d.status]}>
                                        {t(
                                            `disputes.status.${d.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </div>
                                <span className="text-caption text-weak">
                                    {d.receipt_number ??
                                        d.refund_number ??
                                        d.due_name ??
                                        '—'}{' '}
                                    · {d.responsible.name} ·{' '}
                                    {t('disputes.row.age', {
                                        days: d.age_days,
                                    })}
                                    {d.external_party &&
                                        ` · ${t('disputes.row.waiting', { party: d.external_party })}`}
                                </span>
                                <span className="text-caption">{d.reason}</span>
                                {d.resolution && (
                                    <span className="text-caption text-weak">
                                        {d.outcome &&
                                            `${t(`disputes.outcome.${d.outcome}` as TranslationKey)}: `}
                                        {d.resolution}
                                    </span>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Files files={d.files} />
                                    {d.can.resolve && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'resolve',
                                                    id: d.id,
                                                })
                                            }
                                            data-test="resolve-dispute"
                                        >
                                            {t('disputes.resolve.action')}
                                        </Button>
                                    )}
                                    {d.can.await && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'await',
                                                    id: d.id,
                                                })
                                            }
                                        >
                                            {t('disputes.await.action')}
                                        </Button>
                                    )}
                                    {d.can.resume && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'resume',
                                                    id: d.id,
                                                })
                                            }
                                        >
                                            {t('disputes.resume.action')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {documents.length > 0 && (
                <section className="grid gap-2" data-test="documents">
                    <h3 className="text-label text-weak">
                        {t('invoices.section.title')}
                    </h3>
                    <ul className="grid gap-2">
                        {documents.map((d) => (
                            <li
                                key={d.id}
                                className="grid gap-1 rounded-md border border-stroke-weak p-2"
                                data-test="document-row"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-semibold text-strong">
                                        {t(
                                            `invoices.kind.${d.kind}` as TranslationKey,
                                        )}{' '}
                                        {d.number && (
                                            <bdi dir="ltr">{d.number}</bdi>
                                        )}
                                    </span>
                                    <Badge variant={DOCUMENT_TONE[d.status]}>
                                        {t(
                                            `invoices.status.${d.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </div>
                                <span className="text-caption text-weak">
                                    {t(
                                        `invoices.event.${d.event}` as TranslationKey,
                                    )}
                                    {(d.receipt_number ?? d.refund_number) &&
                                        ` · ${d.receipt_number ?? d.refund_number}`}
                                    {d.source && ` · ${d.source}`}
                                    {d.issued_on &&
                                        ` · ${formatDate(d.issued_on, locale)}`}
                                    {d.amount_minor !== undefined &&
                                        d.amount_minor !== null && (
                                            <>
                                                {' · '}
                                                <SettlementMoney
                                                    minor={d.amount_minor}
                                                />
                                            </>
                                        )}
                                </span>
                                {d.correction_reason && (
                                    <span className="text-caption text-danger-text">
                                        {d.correction_reason}
                                    </span>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Files files={d.files} />
                                    {d.can.attach && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'link',
                                                    id: d.id,
                                                })
                                            }
                                        >
                                            {t('invoices.link.action')}
                                        </Button>
                                    )}
                                    {d.can.review && (
                                        <>
                                            <Button
                                                type="button"
                                                size="sm"
                                                loading={accept.busy}
                                                onClick={() =>
                                                    acceptDocument(
                                                        d.id,
                                                        d.lock_version,
                                                    )
                                                }
                                                data-test="accept-document"
                                            >
                                                {t('invoices.review.accept')}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'document_fix',
                                                        id: d.id,
                                                    })
                                                }
                                            >
                                                {t('invoices.review.fix')}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {open?.type === 'refund' && (
                <RefundRequestDialog
                    url={RefundController.store.url(orderId)}
                    orderNumber={orderNumber}
                    lockVersion={lockVersion}
                    payments={payments}
                    onClose={close}
                    onDone={done(t('refunds.request.done'))}
                />
            )}
            {open?.type === 'execute' && refund(open.id) && (
                <RefundExecuteDialog
                    url={RefundController.confirm.url(open.id)}
                    orderNumber={orderNumber}
                    refund={refund(open.id)!}
                    onClose={close}
                    onDone={(r) =>
                        done(
                            t('refunds.execute.done', {
                                number: r.number ?? '',
                            }),
                        )(r)
                    }
                />
            )}
            {open &&
                (open.type === 'start' || open.type === 'retry') &&
                refund(open.id) && (
                    <SettlementFieldDialog
                        title={t(
                            `refunds.${open.type}.title` as TranslationKey,
                        )}
                        description={t(
                            `refunds.${open.type}.description` as TranslationKey,
                        )}
                        confirm={t(
                            `refunds.${open.type}.action` as TranslationKey,
                        )}
                        field="reference"
                        label={t('refunds.field.reference')}
                        required={false}
                        multiline={false}
                        url={RefundController[open.type].url(open.id)}
                        lockVersion={refund(open.id)!.lock_version}
                        onClose={close}
                        onDone={done()}
                    />
                )}
            {open &&
                (open.type === 'fail' || open.type === 'cancel') &&
                refund(open.id) && (
                    <SettlementFieldDialog
                        title={t(
                            `refunds.${open.type}.title` as TranslationKey,
                        )}
                        description={t(
                            `refunds.${open.type}.description` as TranslationKey,
                        )}
                        confirm={t(
                            `refunds.${open.type}.action` as TranslationKey,
                        )}
                        field="reason"
                        label={t('finance.field.reason')}
                        url={RefundController[open.type].url(open.id)}
                        lockVersion={refund(open.id)!.lock_version}
                        destructive
                        onClose={close}
                        onDone={done()}
                    />
                )}
            {open?.type === 'correct' &&
                payments.find((p) => p.id === open.id) && (
                    <CorrectPaymentDialog
                        url={CorrectionController.store.url(open.id)}
                        orderId={orderId}
                        lockVersion={lockVersion}
                        payment={payments.find((p) => p.id === open.id)!}
                        today={today}
                        onClose={close}
                        onDone={(r) =>
                            done(
                                t('refunds.correct.done', {
                                    number: r.number ?? '',
                                }),
                            )(r)
                        }
                    />
                )}
            {open?.type === 'dispute' && (
                <DisputeDialog
                    url={DisputeController.store.url(orderId)}
                    orderNumber={orderNumber}
                    lockVersion={lockVersion}
                    settlement={settlement}
                    dues={dues}
                    onClose={close}
                    onDone={done(t('disputes.open.done'))}
                />
            )}
            {open?.type === 'await' && dispute(open.id) && (
                <SettlementFieldDialog
                    title={t('disputes.await.title')}
                    description={t('disputes.await.description')}
                    confirm={t('disputes.await.action')}
                    field="external_party"
                    label={t('disputes.field.external_party')}
                    multiline={false}
                    url={DisputeController.await.url(open.id)}
                    lockVersion={dispute(open.id)!.lock_version}
                    onClose={close}
                    onDone={done()}
                />
            )}
            {open?.type === 'resume' && dispute(open.id) && (
                <SettlementFieldDialog
                    title={t('disputes.resume.title')}
                    description={t('disputes.resume.description')}
                    confirm={t('disputes.resume.action')}
                    field="note"
                    label={t('disputes.field.answer')}
                    url={DisputeController.resume.url(open.id)}
                    lockVersion={dispute(open.id)!.lock_version}
                    onClose={close}
                    onDone={done()}
                />
            )}
            {open?.type === 'resolve' && dispute(open.id) && (
                <DisputeResolveDialog
                    url={DisputeController.resolve.url(open.id)}
                    orderNumber={orderNumber}
                    dispute={dispute(open.id)!}
                    onClose={close}
                    onDone={done(t('disputes.resolve.done'))}
                />
            )}
            {open?.type === 'link' && (
                <DocumentLinkDialog
                    url={
                        open.id === null
                            ? ExternalDocumentController.link.url(orderId)
                            : ExternalDocumentController.attach.url(open.id)
                    }
                    orderNumber={orderNumber}
                    document={
                        open.id === null ? null : (document(open.id) ?? null)
                    }
                    settlement={settlement}
                    today={today}
                    onClose={close}
                    onDone={done(t('invoices.link.done'))}
                />
            )}
            {open?.type === 'request_document' && (
                <DocumentLinkDialog
                    url={ExternalDocumentController.store.url(orderId)}
                    orderNumber={orderNumber}
                    document={null}
                    settlement={settlement}
                    today={today}
                    request
                    onClose={close}
                    onDone={done(t('invoices.request.done'))}
                />
            )}
            {open?.type === 'document_fix' && document(open.id) && (
                <SettlementFieldDialog
                    title={t('invoices.review.fix_title')}
                    description={t('invoices.review.fix_description')}
                    confirm={t('invoices.review.fix')}
                    field="reason"
                    label={t('finance.field.reason')}
                    url={ExternalDocumentController.correction.url(open.id)}
                    lockVersion={document(open.id)!.lock_version}
                    onClose={close}
                    onDone={done()}
                />
            )}
        </Card>
    );
}

import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    FileSignature,
    FileText,
    FileUp,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/Agreements/AgreementController';
import ChangeController from '@/actions/App/Http/Controllers/Agreements/ChangeController';
import DocumentController from '@/actions/App/Http/Controllers/Agreements/DocumentController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import {
    ContractDialog,
    CreateChangeDialog,
    DecisionText,
    DesignDialog,
    DocumentView,
    ExtendDialog,
    ExternalApprovalDialog,
    LinesTable,
    ReasonDialog,
    SendDialog,
} from '@/components/agreement/dialogs';
import { newKey, toMajor } from '@/components/agreement/money';
import { PricedEditor } from '@/components/agreement/priced-editor';
import {
    ChangeStatusBadge,
    DocumentStatusBadge,
} from '@/components/agreement/status';
import { TotalsSummary } from '@/components/agreement/summary';
import type {
    AgreementDocument,
    AgreementPayload,
    FlowActionOption,
    PricedContent,
} from '@/components/agreement/types';
import { useAgreementCall } from '@/components/agreement/use-agreement-call';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { PageHeader } from '@/components/opsone/page-header';
import { lineName } from '@/components/orders/lines';
import { KindBadge, StatusBadge } from '@/components/orders/status';
import type { OrderLine, OrderRow } from '@/components/orders/types';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDate, formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    order: OrderRow & { needs_measurement: boolean; needs_design: boolean };
    agreement: AgreementPayload;
    lines: OrderLine[];
    actions: FlowActionOption[];
};

type Open =
    | { type: 'edit'; id: number }
    | { type: 'send'; id: number }
    | { type: 'external'; id: number }
    | { type: 'view'; id: number }
    | { type: 'extend'; id: number }
    | { type: 'cancel'; id: number }
    | { type: 'design'; replaces: number | null }
    | { type: 'contract' }
    | { type: 'change_create' }
    | { type: 'change_edit' }
    | { type: 'change_present' }
    | { type: 'change_apply' }
    | { type: 'change_revision' }
    | { type: 'change_withdraw' }
    | null;

/**
 * AgrOrder (+ AgrEmpty, AgrMobile, AgrSupervisor, AgrEnglish, AgrDark, AgrChangePending): the order's agreement,
 * its approval documents and change requests. Every action re-checks on the server; amounts are absent from the
 * payload for viewers without P-37/P-29 (supervisor, executor) — the page never hides them itself.
 */
export default function OrderAgreement({
    order,
    agreement,
    lines,
    actions,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [payload, setPayload] = useState(agreement);
    const [fromProps, setFromProps] = useState(agreement);
    // A page visit (reload, language switch) brings fresh props: they win over the last write's payload.
    if (fromProps !== agreement) {
        setFromProps(agreement);
        setPayload(agreement);
    }
    const [open, setOpen] = useState<Open>(null);
    const creating = useAgreementCall<{
        document_id: number;
        agreement: AgreementPayload;
    }>();
    const [quoteKey] = useState(newKey);
    const revising = useAgreementCall<{
        document_id: number;
        agreement: AgreementPayload;
    }>();

    const {
        agreement: active,
        documents,
        change,
        can,
        amounts_visible: amounts,
    } = payload;
    const doc = (id: number) => documents.find((d) => d.id === id);
    const done = (next: AgreementPayload, message?: string) => {
        setPayload(next);
        setOpen(null);
        if (message) {
            notify('success', { title: message });
        }
    };
    const pendingChange =
        change &&
        ['draft', 'awaiting_approval', 'needs_revision'].includes(change.status)
            ? change
            : null;

    const createQuote = async () => {
        const data = await creating.run(
            'POST',
            AgreementController.storeQuote.url(order.id),
            {
                idempotency_key: quoteKey,
            },
        );
        if (data) {
            setPayload(data.agreement);
            setOpen({ type: 'edit', id: data.document_id });
        }
    };

    const revise = async (document: AgreementDocument) => {
        const data = await revising.run(
            'POST',
            DocumentController.revise.url(document.id),
            {
                idempotency_key: newKey(),
            },
        );
        if (data) {
            setPayload(data.agreement);
            setOpen({ type: 'edit', id: data.document_id });
        }
    };

    const quote = documents.find(
        (d) => d.kind === 'quote' && d.status !== 'cancelled',
    );
    const quoteContent = quote
        ? (quote.content as unknown as PricedContent)
        : null;
    const editing = open?.type === 'edit' ? doc(open.id) : undefined;
    const sending = open?.type === 'send' ? doc(open.id) : undefined;
    const approving = open?.type === 'external' ? doc(open.id) : undefined;
    const viewing = open?.type === 'view' ? doc(open.id) : undefined;
    const extending = open?.type === 'extend' ? doc(open.id) : undefined;
    const cancelling = open?.type === 'cancel' ? doc(open.id) : undefined;
    const lineOptions = lines.map((l) => ({
        id: l.id,
        name: lineName(l, locale),
    }));

    return (
        <>
            <Head title={`${t('agreements.title')} · ${order.number}`} />
            <PageHeader
                title={t('agreements.title')}
                description={`${order.number} · ${order.customer.name}`}
                actions={
                    <Button asChild variant="secondary">
                        <Link href={OrderController.show.url(order.id)}>
                            {t('agreements.back_to_order')}
                        </Link>
                    </Button>
                }
            />

            <div
                className="mb-4 flex flex-wrap items-center gap-2"
                data-test="agreement-header"
            >
                <KindBadge kind={order.kind} />
                <StatusBadge status={order.status} />
                <span className="text-weak">
                    {t('orders.field.branch')}:{' '}
                    <span className="font-semibold text-strong">
                        {order.branch.name}
                    </span>
                </span>
                <span className="text-weak">
                    {t('orders.field.assignee')}:{' '}
                    <span className="font-semibold text-strong">
                        {order.assignee?.name ?? t('orders.unassigned')}
                    </span>
                </span>
            </div>

            {pendingChange && pendingChange.status !== 'draft' && (
                <Alert
                    variant="warning"
                    className="mb-4"
                    data-test="change-pending-banner"
                >
                    <AlertBody>
                        <AlertTitle>
                            {t('agreements.change.banner', {
                                number: pendingChange.number,
                            })}
                        </AlertTitle>
                        <p>
                            {pendingChange.affected.length > 0
                                ? t('agreements.change.banner_held', {
                                      work: pendingChange.affected
                                          .map((a) => a.name)
                                          .join('، '),
                                  })
                                : t('agreements.change.banner_in_force')}
                        </p>
                    </AlertBody>
                </Alert>
            )}
            {(creating.error || revising.error) && (
                <Alert variant="danger" className="mb-4" role="alert">
                    <AlertBody>{creating.error ?? revising.error}</AlertBody>
                </Alert>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid min-w-0 content-start gap-6 lg:col-span-2">
                    {!active && documents.length === 0 ? (
                        <Card data-test="agreement-empty">
                            <EmptyState
                                icon={FileText}
                                title={t('agreements.empty.title')}
                                description={t('agreements.empty.body')}
                                action={
                                    <>
                                        {can.upload_design && (
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'design',
                                                        replaces: null,
                                                    })
                                                }
                                            >
                                                <Icon icon={FileUp} />
                                                {t('agreements.design.upload')}
                                            </Button>
                                        )}
                                        {can.create_quote && (
                                            <Button
                                                type="button"
                                                onClick={createQuote}
                                                loading={creating.busy}
                                                data-test="create-quote"
                                            >
                                                <Icon icon={Plus} />
                                                {t('agreements.quote.create')}
                                            </Button>
                                        )}
                                    </>
                                }
                            />
                            <dl className="grid gap-2 border-t border-stroke-weak pt-4 sm:grid-cols-2">
                                <div className="flex justify-between gap-2">
                                    <dt className="text-weak">
                                        {t('orders.field.needs_measurement')}
                                    </dt>
                                    <dd className="font-semibold text-strong">
                                        {order.needs_measurement
                                            ? t('agreements.yes')
                                            : t('agreements.no')}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <dt className="text-weak">
                                        {t('orders.field.needs_design')}
                                    </dt>
                                    <dd className="font-semibold text-strong">
                                        {order.needs_design
                                            ? t('agreements.yes')
                                            : t('agreements.no')}
                                    </dd>
                                </div>
                            </dl>
                        </Card>
                    ) : (
                        active && (
                            <Card data-test="active-agreement">
                                <CardHeader>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <CardTitle>
                                            {t('agreements.active.title', {
                                                version: active.version,
                                            })}
                                        </CardTitle>
                                        <Badge variant="success">
                                            {t('agreements.active.approved')}
                                        </Badge>
                                    </div>
                                    <CardDescription>
                                        {t('agreements.active.source', {
                                            number: active.source.number ?? '',
                                            date: formatDateTime(
                                                active.approved_at,
                                                locale,
                                            ),
                                        })}
                                    </CardDescription>
                                </CardHeader>
                                <div className="text-body">
                                    <DecisionText decision={active.decision} />
                                </div>
                                <LinesTable
                                    content={active.content}
                                    amountsVisible={amounts}
                                />
                                <dl className="grid gap-2 sm:grid-cols-2">
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-weak">
                                            {t(
                                                'agreements.quote.execution_days',
                                            )}
                                        </dt>
                                        <dd className="font-semibold text-strong">
                                            {active.content.execution_days ===
                                            null
                                                ? '—'
                                                : t('agreements.days', {
                                                      count: active.content
                                                          .execution_days,
                                                  })}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-weak">
                                            {t('agreements.prices')}
                                        </dt>
                                        <dd className="font-semibold text-strong">
                                            {active.content.vat.inclusive
                                                ? t('agreements.vat.inclusive')
                                                : t('agreements.vat.exclusive')}
                                        </dd>
                                    </div>
                                </dl>
                            </Card>
                        )
                    )}

                    {(documents.length > 0 || active) && (
                        <Card data-test="documents">
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle>
                                        {t('agreements.documents.title')}
                                    </CardTitle>
                                    <div className="flex flex-wrap gap-2">
                                        {can.upload_design && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'design',
                                                        replaces: null,
                                                    })
                                                }
                                            >
                                                <Icon icon={FileUp} />
                                                {t('agreements.design.upload')}
                                            </Button>
                                        )}
                                        {can.create_quote && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={createQuote}
                                                loading={creating.busy}
                                                data-test="create-quote"
                                            >
                                                <Icon icon={Plus} />
                                                {t('agreements.quote.create')}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                <CardDescription>
                                    {t('agreements.documents.hint')}
                                </CardDescription>
                            </CardHeader>
                            <ul className="grid gap-3">
                                {documents.map((d) => (
                                    <DocumentRow
                                        key={d.id}
                                        document={d}
                                        amounts={amounts}
                                        onOpen={setOpen}
                                        onRevise={revise}
                                    />
                                ))}
                                {payload.contracts_enabled &&
                                    !documents.some(
                                        (d) => d.kind === 'contract',
                                    ) && (
                                        <li className="flex flex-wrap items-center justify-between gap-3 rounded-md p-3 ring-1 ring-stroke-weak">
                                            <div>
                                                <p className="font-semibold text-strong">
                                                    {t(
                                                        'agreements.kind.contract',
                                                    )}{' '}
                                                    <Badge variant="neutral">
                                                        {t('ui.optional')}
                                                    </Badge>
                                                </p>
                                                <p className="text-weak">
                                                    {t(
                                                        'agreements.contract.none',
                                                    )}
                                                </p>
                                            </div>
                                            {can.prepare_contract && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        setOpen({
                                                            type: 'contract',
                                                        })
                                                    }
                                                    data-test="prepare-contract"
                                                >
                                                    <Icon
                                                        icon={FileSignature}
                                                    />
                                                    {t(
                                                        'agreements.contract.prepare',
                                                    )}
                                                </Button>
                                            )}
                                        </li>
                                    )}
                            </ul>
                        </Card>
                    )}

                    {active && (
                        <Card data-test="changes">
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle>
                                        {t('agreements.change.title')}
                                    </CardTitle>
                                    {can.create_change && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() =>
                                                setOpen({
                                                    type: 'change_create',
                                                })
                                            }
                                            data-test="create-change"
                                        >
                                            <Icon icon={ArrowLeftRight} />
                                            {t('agreements.change.create')}
                                        </Button>
                                    )}
                                </div>
                            </CardHeader>
                            {change ? (
                                <div className="grid gap-3 rounded-md p-3 ring-1 ring-stroke-weak">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-semibold text-strong">
                                            <bdi dir="ltr">{change.number}</bdi>{' '}
                                            · {change.description}
                                        </p>
                                        <ChangeStatusBadge
                                            status={change.status}
                                        />
                                    </div>
                                    {change.revision_reason && (
                                        <p className="text-weak">
                                            {t(
                                                'agreements.change.revision_reason',
                                                {
                                                    reason: change.revision_reason,
                                                },
                                            )}
                                        </p>
                                    )}
                                    {amounts &&
                                        change.difference_minor !== undefined &&
                                        change.difference_minor !== null && (
                                            <p className="text-body">
                                                {t(
                                                    'agreements.change.difference',
                                                )}{' '}
                                                <Amount
                                                    value={toMajor(
                                                        change.difference_minor,
                                                    )}
                                                    strong
                                                />
                                                {change.delay_days > 0 &&
                                                    ` · ${t('agreements.change.delay', { count: change.delay_days })}`}
                                            </p>
                                        )}
                                    <LinesTable
                                        content={change.content}
                                        amountsVisible={amounts}
                                        compareTo={active.content}
                                    />
                                    {change.affected.length > 0 && (
                                        <p className="text-weak">
                                            {t(
                                                'agreements.change.affected_list',
                                                {
                                                    work: change.affected
                                                        .map((a) => a.name)
                                                        .join('، '),
                                                },
                                            )}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {change.can.edit && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'change_edit',
                                                    })
                                                }
                                            >
                                                {t('agreements.change.edit')}
                                            </Button>
                                        )}
                                        {change.can.present && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'change_present',
                                                    })
                                                }
                                                data-test="present-change"
                                            >
                                                {t('agreements.change.present')}
                                            </Button>
                                        )}
                                        {change.can.apply && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'change_apply',
                                                    })
                                                }
                                                data-test="apply-change"
                                            >
                                                {t(
                                                    'agreements.change.record_external',
                                                )}
                                            </Button>
                                        )}
                                        {change.can.request_revision && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'change_revision',
                                                    })
                                                }
                                            >
                                                {t(
                                                    'agreements.change.request_revision',
                                                )}
                                            </Button>
                                        )}
                                        {change.can.withdraw && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setOpen({
                                                        type: 'change_withdraw',
                                                    })
                                                }
                                            >
                                                {t(
                                                    'agreements.change.withdraw',
                                                )}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <p className="text-weak">
                                    {t('agreements.change.none')}
                                </p>
                            )}
                        </Card>
                    )}
                </div>

                <aside className="grid min-w-0 content-start gap-6">
                    <Card data-test="agreement-value">
                        <CardHeader>
                            <CardTitle>{t('agreements.value.title')}</CardTitle>
                        </CardHeader>
                        {!amounts ? (
                            <p className="text-weak" data-test="amounts-hidden">
                                {t('agreements.value.hidden')}
                            </p>
                        ) : active ? (
                            <>
                                <TotalsSummary
                                    totals={active.content.totals}
                                    discount={active.content.discount}
                                    vat={active.content.vat}
                                />
                                {(active.settlement_needed_minor ?? 0) > 0 && (
                                    <Alert variant="warning">
                                        <AlertBody>
                                            {t('agreements.value.settlement')}{' '}
                                            <Amount
                                                value={toMajor(
                                                    active.settlement_needed_minor ??
                                                        0,
                                                )}
                                                strong
                                            />
                                        </AlertBody>
                                    </Alert>
                                )}
                                {pendingChange?.content.totals?.total_minor !==
                                    undefined &&
                                    pendingChange.content.totals.total_minor !==
                                        null && (
                                        <div className="border-t border-stroke-weak pt-3">
                                            <p className="text-weak">
                                                {t(
                                                    'agreements.value.after_change',
                                                )}
                                            </p>
                                            <Amount
                                                value={toMajor(
                                                    pendingChange.content.totals
                                                        .total_minor,
                                                )}
                                                strong
                                            />
                                            <p className="text-caption text-weak">
                                                {t(
                                                    'agreements.value.not_before_approval',
                                                )}
                                            </p>
                                        </div>
                                    )}
                            </>
                        ) : quoteContent?.complete ? (
                            <TotalsSummary
                                totals={quoteContent.totals}
                                discount={quoteContent.discount}
                                vat={quoteContent.vat}
                            />
                        ) : (
                            <p className="text-weak">
                                {t('agreements.value.none')}
                            </p>
                        )}
                    </Card>

                    <Card data-test="agreement-history">
                        <CardHeader>
                            <CardTitle>
                                {t('agreements.history.title')}
                            </CardTitle>
                        </CardHeader>
                        {payload.history.length === 0 ? (
                            <p className="text-weak">
                                {t('agreements.history.empty')}
                            </p>
                        ) : (
                            <ol className="grid gap-3">
                                {payload.history.map((h) => (
                                    <li
                                        key={h.id}
                                        className="border-b border-stroke-weak pb-2 last:border-0"
                                    >
                                        <p className="text-strong">
                                            {t(
                                                `agreements.history.${h.machine}.${h.transition}` as TranslationKey,
                                            )}
                                        </p>
                                        <p className="text-caption text-weak">
                                            {(h.actor ??
                                                (h.source === 'customer'
                                                    ? t(
                                                          'agreements.history.customer',
                                                      )
                                                    : t(
                                                          'agreements.history.system',
                                                      ))) +
                                                ' · ' +
                                                formatDateTime(h.at, locale)}
                                        </p>
                                        {h.reason && (
                                            <p className="text-caption text-weak">
                                                {h.reason}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>
                </aside>
            </div>

            {editing &&
                (editing.kind === 'quote' ? (
                    <PricedEditor
                        mode="quote"
                        number={editing.number}
                        content={editing.content as unknown as PricedContent}
                        discount={editing.discount ?? null}
                        lockVersion={editing.lock_version}
                        saveUrl={DocumentController.update.url(editing.id)}
                        discountUrl={DocumentController.requestDiscount.url(
                            editing.id,
                        )}
                        amountsVisible={amounts}
                        onClose={() => setOpen(null)}
                        onSaved={(next) => setPayload(next)}
                    />
                ) : (
                    <DocumentView
                        document={editing}
                        amountsVisible={amounts}
                        onClose={() => setOpen(null)}
                    />
                ))}
            {sending && (
                <SendDialog
                    document={sending}
                    customer={order.customer.name}
                    totalMinor={
                        (
                            sending.content as {
                                totals?: { total_minor?: number | null };
                            }
                        ).totals?.total_minor
                    }
                    url={DocumentController.send.url(sending.id)}
                    defaultDays={14}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('agreements.send.done'))}
                />
            )}
            {approving && approving.content_hash && (
                <ExternalApprovalDialog
                    title={t('agreements.external.title', {
                        number: approving.number,
                    })}
                    summary={t('agreements.external.summary', {
                        date: approving.sent_at
                            ? formatDateTime(approving.sent_at, locale)
                            : '',
                    })}
                    afterNotes={[
                        t('agreements.external.note_same'),
                        t('agreements.external.note_once'),
                    ]}
                    contentHash={approving.content_hash}
                    lockVersion={approving.lock_version}
                    url={DocumentController.externalApproval.url(approving.id)}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('agreements.external.done'))}
                />
            )}
            {viewing && (
                <DocumentView
                    document={viewing}
                    amountsVisible={amounts}
                    onClose={() => setOpen(null)}
                />
            )}
            {extending && (
                <ExtendDialog
                    document={extending}
                    url={DocumentController.extend.url(extending.id)}
                    defaultDays={14}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next)}
                />
            )}
            {cancelling && (
                <ReasonDialog
                    title={t('agreements.cancel.title', {
                        number: cancelling.number,
                    })}
                    description={t('agreements.cancel.hint')}
                    label={t('agreements.reason')}
                    confirm={t('agreements.cancel.confirm')}
                    url={DocumentController.cancel.url(cancelling.id)}
                    lockVersion={cancelling.lock_version}
                    destructive
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next)}
                />
            )}
            {open?.type === 'design' && (
                <DesignDialog
                    orderNumber={order.number}
                    lines={lineOptions}
                    url={AgreementController.storeDesign.url(order.id)}
                    replaces={
                        open.replaces === null
                            ? null
                            : (doc(open.replaces) ?? null)
                    }
                    sendUrl={(id) => DocumentController.send.url(id)}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('agreements.design.done'))}
                />
            )}
            {open?.type === 'contract' && (
                <ContractDialog
                    orderNumber={order.number}
                    hasAgreement={active !== null}
                    url={AgreementController.storeContract.url(order.id)}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next, t('agreements.contract.done'))}
                />
            )}
            {open?.type === 'change_create' && (
                <CreateChangeDialog
                    url={AgreementController.storeChange.url(order.id)}
                    onClose={() => setOpen(null)}
                    onDone={(next) => {
                        setPayload(next);
                        setOpen({ type: 'change_edit' });
                    }}
                />
            )}
            {open?.type === 'change_edit' && change && (
                <PricedEditor
                    mode="change"
                    number={change.number}
                    content={change.content}
                    discount={change.discount ?? null}
                    lockVersion={change.lock_version}
                    saveUrl={ChangeController.update.url(change.id)}
                    discountUrl={ChangeController.requestDiscount.url(
                        change.id,
                    )}
                    change={{
                        description: change.description,
                        affected: change.affected.map((a) => a.key),
                        delay_days: change.delay_days,
                    }}
                    actions={actions}
                    amountsVisible={amounts}
                    onClose={() => setOpen(null)}
                    onSaved={(next) => setPayload(next)}
                />
            )}
            {open?.type === 'change_present' && change && (
                <PresentChange
                    url={ChangeController.present.url(change.id)}
                    lockVersion={change.lock_version}
                    onClose={() => setOpen(null)}
                    onDone={(next) =>
                        done(next, t('agreements.change.presented'))
                    }
                />
            )}
            {open?.type === 'change_apply' && change?.content_hash && (
                <ExternalApprovalDialog
                    title={t('agreements.change.external_title', {
                        name: order.customer.name,
                        number: change.number,
                    })}
                    summary={change.description}
                    afterNotes={[
                        t('agreements.change.external_note_once'),
                        t('agreements.external.note_same'),
                    ]}
                    contentHash={change.content_hash}
                    lockVersion={change.lock_version}
                    url={ChangeController.apply.url(change.id)}
                    onClose={() => setOpen(null)}
                    onDone={(next) =>
                        done(next, t('agreements.change.applied'))
                    }
                />
            )}
            {open?.type === 'change_revision' && change && (
                <ReasonDialog
                    title={t('agreements.change.request_revision')}
                    description={t('agreements.change.revision_hint')}
                    label={t('agreements.reason')}
                    confirm={t('agreements.change.request_revision')}
                    url={ChangeController.requestRevision.url(change.id)}
                    lockVersion={change.lock_version}
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next)}
                />
            )}
            {open?.type === 'change_withdraw' && change && (
                <ReasonDialog
                    title={t('agreements.change.withdraw')}
                    description={t('agreements.change.withdraw_hint')}
                    label={t('agreements.reason')}
                    confirm={t('agreements.change.withdraw')}
                    url={ChangeController.withdraw.url(change.id)}
                    lockVersion={change.lock_version}
                    destructive
                    onClose={() => setOpen(null)}
                    onDone={(next) => done(next)}
                />
            )}
        </>
    );
}

/** One approval document with what can be done on it now. */
function DocumentRow({
    document: d,
    amounts,
    onOpen,
    onRevise,
}: {
    document: AgreementDocument;
    amounts: boolean;
    onOpen: (open: Open) => void;
    onRevise: (document: AgreementDocument) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const total = (d.content as { totals?: { total_minor?: number | null } })
        .totals?.total_minor;

    return (
        <li
            className="grid gap-2 rounded-md p-3 ring-1 ring-stroke-weak"
            data-test={`document-${d.kind}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-strong">
                    {t(`agreements.kind.${d.kind}` as TranslationKey)}{' '}
                    <bdi dir="ltr" className="text-weak">
                        {d.number}
                    </bdi>
                </p>
                <DocumentStatusBadge status={d.status} />
            </div>
            <p className="text-caption text-weak">
                {[
                    d.decision || !d.sent_at
                        ? d.decision
                            ? null
                            : t('agreements.view.draft')
                        : t('agreements.view.sent', {
                              name: d.sent_by ?? '',
                              date: formatDateTime(d.sent_at, locale),
                          }),
                    d.valid_until
                        ? t('agreements.view.valid_until', {
                              date: formatDate(d.valid_until, locale),
                          })
                        : null,
                    d.replaces
                        ? t('agreements.view.replaces', { number: d.replaces })
                        : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </p>
            {d.decision && (
                <div className="text-caption">
                    <DecisionText decision={d.decision} />
                </div>
            )}
            {amounts && total !== undefined && total !== null && (
                <p>
                    <Amount value={toMajor(total)} strong />
                </p>
            )}
            {d.discount?.state === 'needs_decision' && (
                <p className="text-caption text-warning-text">
                    {t('agreements.discount.needs_decision_short')}
                </p>
            )}
            {d.discount?.state === 'requested' && (
                <p className="text-caption text-info-text">
                    {t('agreements.discount.requested')}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => onOpen({ type: 'view', id: d.id })}
                >
                    {t('agreements.action.open')}
                </Button>
                {d.can.edit && d.kind === 'quote' && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => onOpen({ type: 'edit', id: d.id })}
                        data-test="edit-quote"
                    >
                        {t('agreements.action.edit')}
                    </Button>
                )}
                {d.can.send && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => onOpen({ type: 'send', id: d.id })}
                        data-test="send-document"
                    >
                        {t('agreements.action.send')}
                    </Button>
                )}
                {d.can.record_approval && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => onOpen({ type: 'external', id: d.id })}
                        data-test="record-approval"
                    >
                        {t('agreements.action.record_external')}
                    </Button>
                )}
                {d.can.revise && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => onRevise(d)}
                    >
                        {t('agreements.action.replace')}
                    </Button>
                )}
                {d.can.replace_design && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            onOpen({ type: 'design', replaces: d.id })
                        }
                    >
                        {t('agreements.action.replace')}
                    </Button>
                )}
                {d.can.extend && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => onOpen({ type: 'extend', id: d.id })}
                    >
                        {t('agreements.action.extend')}
                    </Button>
                )}
                {d.can.cancel && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onOpen({ type: 'cancel', id: d.id })}
                    >
                        {t('agreements.action.cancel')}
                    </Button>
                )}
            </div>
        </li>
    );
}

/** M-09-T01/T03: fixes the change for the customer's decision and holds the affected work. */
function PresentChange({
    url,
    lockVersion,
    onClose,
    onDone,
}: {
    url: string;
    lockVersion: number;
    onClose: () => void;
    onDone: (next: AgreementPayload) => void;
}) {
    const t = useT();
    const call = useAgreementCall();
    const [key] = useState(newKey);

    return (
        <ReasonlessConfirm
            title={t('agreements.change.present')}
            body={[
                t('agreements.change.present_note_force'),
                t('agreements.change.present_note_held'),
                t('agreements.change.present_note_rest'),
            ]}
            confirm={t('agreements.change.present')}
            busy={call.busy}
            error={call.error}
            onClose={onClose}
            onConfirm={async () => {
                const data = await call.run('POST', url, {
                    idempotency_key: key,
                    lock_version: lockVersion,
                });
                if (data) {
                    onDone(data.agreement);
                }
            }}
        />
    );
}

function ReasonlessConfirm({
    title,
    body,
    confirm,
    busy,
    error,
    onClose,
    onConfirm,
}: {
    title: string;
    body: string[];
    confirm: string;
    busy: boolean;
    error: string | null;
    onClose: () => void;
    onConfirm: () => void;
}) {
    const t = useT();

    return (
        <ConfirmDialog
            title={title}
            confirm={confirm}
            busy={busy}
            error={error}
            onClose={onClose}
            onConfirm={onConfirm}
            cancel={t('ui.cancel')}
        >
            <ul className="grid list-disc gap-1 ps-5 text-body text-weak">
                {body.map((line) => (
                    <li key={line}>{line}</li>
                ))}
            </ul>
        </ConfirmDialog>
    );
}

function ConfirmDialog({
    title,
    confirm,
    cancel,
    busy,
    error,
    onClose,
    onConfirm,
    children,
}: {
    title: string;
    confirm: string;
    cancel: string;
    busy: boolean;
    error: string | null;
    onClose: () => void;
    onConfirm: () => void;
    children: ReactNode;
}) {
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent size="sm" data-test="confirm-dialog">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {title}
                    </DialogDescription>
                </DialogHeader>
                {children}
                {error && (
                    <Alert variant="danger" role="alert">
                        <AlertBody>{error}</AlertBody>
                    </Alert>
                )}
                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {cancel}
                    </Button>
                    <Button
                        type="button"
                        onClick={onConfirm}
                        loading={busy}
                        data-test="confirm"
                    >
                        {confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

OrderAgreement.layout = {
    breadcrumbs: [{ title: 'orders.nav', href: OrderController.index() }],
};

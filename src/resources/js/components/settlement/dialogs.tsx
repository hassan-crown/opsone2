import { useState } from 'react';
import { minorToText, newKey, toMajor } from '@/components/agreement/money';
import { Shell, textToMinor } from '@/components/finance/dialogs';
import type { FinanceDue } from '@/components/finance/types';
import { Amount } from '@/components/opsone/amount';
import {
    Field,
    RadioGroup,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    ExternalDocument,
    SettlementDispute,
    SettlementDone,
    SettlementPayload,
    SettlementPayment,
    SettlementRefund,
} from './types';
import {
    DISPUTE_OUTCOMES,
    DISPUTE_SUBJECTS,
    DOCUMENT_EVENTS,
    DOCUMENT_KINDS,
} from './types';
import { useSettlementCall } from './use-settlement-call';

const METHODS = ['transfer', 'cash', 'card', 'cheque'] as const;

function Notes({ items }: { items: string[] }) {
    return (
        <ul className="grid list-disc gap-1 ps-5 text-body text-weak">
            {items.map((item) => (
                <li key={item}>{item}</li>
            ))}
        </ul>
    );
}

function FileField({
    label,
    hint,
    error,
    required,
    onChange,
}: {
    label: string;
    hint?: string;
    error?: string;
    required?: boolean;
    onChange: (file: File | null) => void;
}) {
    return (
        <Field label={label} hint={hint} error={error} required={required}>
            {(control) => (
                <input
                    {...control}
                    type="file"
                    className="field-control py-2"
                    onChange={(e) => onChange(e.target.files?.[0] ?? null)}
                    data-test="settlement-file"
                />
            )}
        </Field>
    );
}

/** A money figure, or «hidden» without P-37 (never 0 for an unknown amount). */
export function SettlementMoney({
    minor,
}: {
    minor: number | null | undefined;
}) {
    const t = useT();
    if (minor === null || minor === undefined) {
        return <span className="text-weak">{t('finance.hidden')}</span>;
    }

    return <Amount value={toMajor(minor)} />;
}

function Figure({
    label,
    minor,
}: {
    label: string;
    minor: number | undefined;
}) {
    return (
        <div className="bg-surface-sunken grid gap-1 rounded-md p-3">
            <span className="text-caption text-weak">{label}</span>
            <span className="font-semibold text-strong">
                <SettlementMoney minor={minor} />
            </span>
        </div>
    );
}

function paymentLabel(p: SettlementPayment): string {
    return `${p.receipt_number ?? '—'}${p.amount_minor !== undefined ? ` · ${minorToText(p.amount_minor)}` : ''}`;
}

/** FinRefund (P-42): the request reserves its amount from the payment's refundable limit; it reduces nothing yet. */
export function RefundRequestDialog({
    url,
    orderNumber,
    lockVersion,
    payments,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    lockVersion: number;
    payments: SettlementPayment[];
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const t = useT();
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const options = payments.filter((p) => p.refundable);
    const [paymentId, setPaymentId] = useState(String(options[0]?.id ?? ''));
    const payment = options.find((p) => String(p.id) === paymentId);
    const [amount, setAmount] = useState(
        payment?.refundable_minor ? minorToText(payment.refundable_minor) : '',
    );
    const [method, setMethod] = useState('transfer');
    const [reason, setReason] = useState('');
    const minor = textToMinor(amount);
    const over =
        minor !== null &&
        payment?.refundable_minor !== undefined &&
        minor > payment.refundable_minor;

    const submit = async () => {
        const data = await call.run(url, {
            idempotency_key: key,
            lock_version: lockVersion,
            payment_id: Number(paymentId),
            amount,
            method,
            reason,
        });
        if (data) {
            onDone(data);
        }
    };

    return (
        <Shell
            test="refund-request-dialog"
            title={t('refunds.request.title', { order: orderNumber })}
            description={t('refunds.request.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    disabled={!paymentId}
                    data-test="confirm-refund-request"
                >
                    {t('refunds.request.confirm')}
                </Button>
            }
        >
            {payment && (
                <div className="grid gap-2 sm:grid-cols-3">
                    <Figure
                        label={t('refunds.figure.refundable')}
                        minor={payment.refundable_minor}
                    />
                    <Figure
                        label={t('refunds.figure.reserved')}
                        minor={payment.reserved_minor}
                    />
                    <Figure
                        label={t('refunds.figure.refunded')}
                        minor={payment.refunded_minor}
                    />
                </div>
            )}
            <SelectField
                label={t('refunds.field.payment')}
                value={paymentId}
                required
                error={call.fieldError('payment_id')}
                onChange={(e) => setPaymentId(e.target.value)}
                options={options.map((p) => ({
                    value: String(p.id),
                    label: paymentLabel(p),
                }))}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label={t('finance.field.amount')}
                    inputMode="decimal"
                    dir="ltr"
                    value={amount}
                    required
                    error={
                        call.fieldError('amount') ??
                        (over ? t('refunds.validation.over_limit') : undefined)
                    }
                    onChange={(e) => setAmount(e.target.value)}
                    data-test="refund-amount"
                />
                <SelectField
                    label={t('refunds.field.method')}
                    value={method}
                    required
                    onChange={(e) => setMethod(e.target.value)}
                    options={METHODS.map((m) => ({
                        value: m,
                        label: t(`finance.method.${m}` as TranslationKey),
                    }))}
                />
            </div>
            <TextareaField
                label={t('finance.field.reason')}
                value={reason}
                required
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
                data-test="refund-reason"
            />
            <Notes
                items={[
                    t('refunds.request.note_money'),
                    t('refunds.request.note_reserve'),
                    t('refunds.request.note_commission'),
                ]}
            />
        </Shell>
    );
}

/** FinRefundExec (P-43): the refund was paid outside the system — reference and proof; the net collected drops once. */
export function RefundExecuteDialog({
    url,
    orderNumber,
    refund,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    refund: SettlementRefund;
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const t = useT();
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const [reference, setReference] = useState(refund.reference ?? '');
    const [note, setNote] = useState('');
    const [file, setFile] = useState<File | null>(null);

    const submit = async () => {
        if (!file) {
            call.setError(t('refunds.execute.proof_required'));

            return;
        }
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('lock_version', String(refund.lock_version));
        form.append('reference', reference);
        form.append('note', note);
        form.append('proof', file);
        const data = await call.run(url, form);
        if (data) {
            onDone(data);
        }
    };

    return (
        <Shell
            test="refund-execute-dialog"
            title={t('refunds.execute.title', { order: orderNumber })}
            description={t('refunds.execute.description', {
                receipt: refund.receipt_number ?? '—',
            })}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-refund-execute"
                >
                    {t('refunds.execute.confirm')}
                </Button>
            }
        >
            <Figure
                label={t('refunds.figure.amount')}
                minor={refund.amount_minor}
            />
            <TextField
                label={t('refunds.field.reference')}
                dir="ltr"
                value={reference}
                required
                error={call.fieldError('reference')}
                onChange={(e) => setReference(e.target.value)}
                data-test="refund-reference"
            />
            <FileField
                label={t('refunds.field.proof')}
                required
                error={call.fieldError('proof') ?? call.fieldError('file')}
                onChange={setFile}
            />
            <TextareaField
                label={t('finance.field.note')}
                value={note}
                onChange={(e) => setNote(e.target.value)}
            />
            <Notes
                items={[
                    t('refunds.execute.note_once'),
                    t('refunds.execute.note_unknown'),
                ]}
            />
        </Shell>
    );
}

/** One text field and a key: refund start / retry (reference), failure / withdrawal / document correction (reason), dispute steps. */
export function SettlementFieldDialog({
    title,
    description,
    confirm,
    field,
    label,
    required = true,
    multiline = true,
    url,
    lockVersion,
    destructive,
    onClose,
    onDone,
}: {
    title: string;
    description: string;
    confirm: string;
    field: string;
    label: string;
    required?: boolean;
    multiline?: boolean;
    url: string;
    lockVersion: number;
    destructive?: boolean;
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const [value, setValue] = useState('');

    const submit = async () => {
        const data = await call.run(url, {
            idempotency_key: key,
            lock_version: lockVersion,
            [field]: value,
        });
        if (data) {
            onDone(data);
        }
    };

    return (
        <Shell
            test="settlement-field-dialog"
            size="sm"
            title={title}
            description={description}
            error={call.fieldError(field) ? null : call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    variant={destructive ? 'destructive' : 'default'}
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-settlement-field"
                >
                    {confirm}
                </Button>
            }
        >
            {multiline ? (
                <TextareaField
                    label={label}
                    value={value}
                    required={required}
                    error={call.fieldError(field)}
                    onChange={(e) => setValue(e.target.value)}
                />
            ) : (
                <TextField
                    label={label}
                    dir="ltr"
                    value={value}
                    required={required}
                    error={call.fieldError(field)}
                    onChange={(e) => setValue(e.target.value)}
                />
            )}
        </Shell>
    );
}

/** FinCorrect (P-41): a documented correction — the receipt stays, a correction voucher is added; never a bank refund. */
export function CorrectPaymentDialog({
    url,
    orderId,
    lockVersion,
    payment,
    today,
    onClose,
    onDone,
}: {
    url: string;
    orderId: number;
    lockVersion: number;
    payment: SettlementPayment;
    today: string;
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const t = useT();
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const [kind, setKind] = useState<'void' | 'amend'>('amend');
    const [amount, setAmount] = useState(minorToText(payment.amount_minor));
    const [method, setMethod] = useState(
        payment.method === 'online' ? 'transfer' : payment.method,
    );
    const [date, setDate] = useState(payment.paid_on);
    const [reference, setReference] = useState(payment.reference ?? '');
    const [reason, setReason] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const minor = kind === 'void' ? 0 : textToMinor(amount);
    const difference =
        minor !== null && payment.amount_minor !== undefined
            ? minor - payment.amount_minor
            : null;

    const submit = async () => {
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('lock_version', String(lockVersion));
        form.append('order_id', String(orderId));
        form.append('kind', kind);
        form.append('reason', reason);
        if (kind === 'amend') {
            form.append('amount', amount);
            form.append('method', method);
            form.append('paid_on', date);
            form.append('reference', reference);
            if (file) {
                form.append('evidence', file);
            }
        }
        const data = await call.run(url, form);
        if (data) {
            onDone(data);
        }
    };

    return (
        <Shell
            test="correct-payment-dialog"
            title={t('refunds.correct.title')}
            description={t('refunds.correct.description', {
                receipt: payment.receipt_number ?? '—',
            })}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-correct-payment"
                >
                    {t('refunds.correct.confirm')}
                </Button>
            }
        >
            <RadioGroup
                legend={t('refunds.correct.kind')}
                name="correction-kind"
                value={kind}
                onChange={(v) => setKind(v as 'void' | 'amend')}
                options={[
                    { value: 'amend', label: t('refunds.correct.kind_amend') },
                    { value: 'void', label: t('refunds.correct.kind_void') },
                ]}
            />
            {kind === 'amend' && (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={t('refunds.correct.right_amount')}
                            inputMode="decimal"
                            dir="ltr"
                            value={amount}
                            required
                            error={call.fieldError('amount')}
                            onChange={(e) => setAmount(e.target.value)}
                            data-test="correct-amount"
                        />
                        <SelectField
                            label={t('finance.field.method')}
                            value={method}
                            required
                            onChange={(e) => setMethod(e.target.value)}
                            options={METHODS.map((m) => ({
                                value: m,
                                label: t(
                                    `finance.method.${m}` as TranslationKey,
                                ),
                            }))}
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={t('finance.field.paid_on')}
                            type="date"
                            dir="ltr"
                            max={today}
                            value={date}
                            required
                            error={call.fieldError('paid_on')}
                            onChange={(e) => setDate(e.target.value)}
                        />
                        <TextField
                            label={t('finance.field.reference')}
                            dir="ltr"
                            value={reference}
                            error={call.fieldError('reference')}
                            onChange={(e) => setReference(e.target.value)}
                        />
                    </div>
                    <FileField
                        label={t('refunds.correct.evidence')}
                        error={call.fieldError('evidence')}
                        onChange={setFile}
                    />
                </>
            )}
            {difference !== null && (
                <div className="bg-surface-sunken flex items-center justify-between rounded-md p-3">
                    <span className="text-weak">
                        {t('refunds.correct.difference')}
                    </span>
                    <span className="font-semibold tabular-nums" dir="ltr">
                        {difference > 0 ? '+' : ''}
                        {minorToText(difference)}
                    </span>
                </div>
            )}
            <TextareaField
                label={t('finance.field.reason')}
                value={reason}
                required
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
                data-test="correct-reason"
            />
            <Notes
                items={[
                    t('refunds.correct.note_receipt'),
                    t('refunds.correct.note_stages'),
                    t('refunds.correct.note_not_refund'),
                ]}
            />
        </Shell>
    );
}

/** FinDispute (P-85): a dispute on a movement; it holds the disputed part from reminders and moves no money. */
export function DisputeDialog({
    url,
    orderNumber,
    lockVersion,
    settlement,
    dues,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    lockVersion: number;
    settlement: SettlementPayload;
    dues: FinanceDue[];
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const t = useT();
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const movements = [
        ...settlement.payments.map((p) => ({
            value: `payment:${p.id}`,
            label: `${t('disputes.movement.payment')} ${paymentLabel(p)}`,
            minor: p.amount_minor,
        })),
        ...settlement.refunds.map((r) => ({
            value: `refund:${r.id}`,
            label: `${t('disputes.movement.refund')} ${r.number ?? `#${r.id}`}`,
            minor: r.amount_minor,
        })),
        ...dues.map((d) => ({
            value: `due:${d.id}`,
            label: `${t('disputes.movement.due')} ${d.name ?? d.reference}`,
            minor: d.amount_minor,
        })),
    ];
    const [movement, setMovement] = useState(movements[0]?.value ?? '');
    const [subject, setSubject] = useState<string>('settlement');
    const [amount, setAmount] = useState('');
    const [amountNote, setAmountNote] = useState('');
    const [responsible, setResponsible] = useState(
        String(settlement.members[0]?.id ?? ''),
    );
    const [reason, setReason] = useState('');
    const chosen = movements.find((m) => m.value === movement);
    const minor = textToMinor(amount);
    const above =
        minor !== null && chosen?.minor !== undefined && minor > chosen.minor;

    const submit = async () => {
        const [type, id] = movement.split(':');
        const data = await call.run(url, {
            idempotency_key: key,
            lock_version: lockVersion,
            [`${type}_id`]: Number(id),
            subject,
            amount,
            amount_note: amountNote || null,
            responsible_id: Number(responsible),
            reason,
        });
        if (data) {
            onDone(data);
        }
    };

    return (
        <Shell
            test="dispute-dialog"
            title={t('disputes.open.title', { order: orderNumber })}
            description={t('disputes.open.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    disabled={!movement}
                    data-test="confirm-dispute"
                >
                    {t('disputes.open.confirm')}
                </Button>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    label={t('disputes.field.movement')}
                    value={movement}
                    required
                    error={call.fieldError('payment_id')}
                    onChange={(e) => setMovement(e.target.value)}
                    options={movements.map(({ value, label }) => ({
                        value,
                        label,
                    }))}
                />
                <SelectField
                    label={t('disputes.field.subject')}
                    value={subject}
                    required
                    onChange={(e) => setSubject(e.target.value)}
                    options={DISPUTE_SUBJECTS.map((s) => ({
                        value: s,
                        label: t(`disputes.subject.${s}` as TranslationKey),
                    }))}
                />
                <TextField
                    label={t('disputes.field.amount')}
                    inputMode="decimal"
                    dir="ltr"
                    value={amount}
                    required
                    error={call.fieldError('amount')}
                    onChange={(e) => setAmount(e.target.value)}
                    data-test="dispute-amount"
                />
                <SelectField
                    label={t('disputes.field.responsible')}
                    value={responsible}
                    required
                    error={call.fieldError('responsible_id')}
                    onChange={(e) => setResponsible(e.target.value)}
                    options={settlement.members.map((m) => ({
                        value: String(m.id),
                        label: m.name,
                    }))}
                />
            </div>
            {(above || call.fieldError('amount_note')) && (
                <TextField
                    label={t('disputes.field.amount_note')}
                    hint={t('disputes.field.amount_note_hint')}
                    value={amountNote}
                    required
                    error={call.fieldError('amount_note')}
                    onChange={(e) => setAmountNote(e.target.value)}
                />
            )}
            <TextareaField
                label={t('finance.field.reason')}
                value={reason}
                required
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
                data-test="dispute-reason"
            />
            <Notes
                items={[
                    t('disputes.open.note_closure'),
                    t('disputes.open.note_reminders'),
                    t('disputes.open.note_money'),
                ]}
            />
        </Shell>
    );
}

/** FinDisputeResolve (P-85): resolved with what was verified and the proof; a refund or correction runs from its own action. */
export function DisputeResolveDialog({
    url,
    orderNumber,
    dispute,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    dispute: SettlementDispute;
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const t = useT();
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const [outcome, setOutcome] = useState<string>('upheld');
    const [resolution, setResolution] = useState('');
    const [file, setFile] = useState<File | null>(null);

    const submit = async () => {
        if (!file) {
            call.setError(t('disputes.resolve.proof_required'));

            return;
        }
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('lock_version', String(dispute.lock_version));
        form.append('outcome', outcome);
        form.append('resolution', resolution);
        form.append('proof', file);
        const data = await call.run(url, form);
        if (data) {
            onDone(data);
        }
    };

    return (
        <Shell
            test="dispute-resolve-dialog"
            title={t('disputes.resolve.title', { order: orderNumber })}
            description={dispute.reason}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-dispute-resolve"
                >
                    {t('disputes.resolve.confirm')}
                </Button>
            }
        >
            <SelectField
                label={t('disputes.field.outcome')}
                value={outcome}
                required
                onChange={(e) => setOutcome(e.target.value)}
                options={DISPUTE_OUTCOMES.map((o) => ({
                    value: o,
                    label: t(`disputes.outcome.${o}` as TranslationKey),
                }))}
            />
            <TextareaField
                label={t('disputes.field.resolution')}
                value={resolution}
                required
                error={call.fieldError('resolution')}
                onChange={(e) => setResolution(e.target.value)}
            />
            <FileField
                label={t('disputes.field.proof')}
                required
                error={call.fieldError('proof')}
                onChange={setFile}
            />
            <Notes
                items={[
                    t('disputes.resolve.note_reminders'),
                    t('disputes.resolve.note_refund'),
                ]}
            />
        </Shell>
    );
}

/** FinInvoiceLink (P-44): link the document the accounting system issued — linking is not a compliance check (BR-20). */
export function DocumentLinkDialog({
    url,
    orderNumber,
    document,
    settlement,
    today,
    request,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    document: ExternalDocument | null;
    settlement: SettlementPayload;
    today: string;
    request?: boolean;
    onClose: () => void;
    onDone: SettlementDone;
}) {
    const t = useT();
    const call = useSettlementCall();
    const [key] = useState(newKey);
    const [kind, setKind] = useState<string>(
        document?.kind ?? 'simplified_invoice',
    );
    const [event, setEvent] = useState<string>(document?.event ?? 'payment');
    const [link, setLink] = useState(String(settlement.payments[0]?.id ?? ''));
    const [source, setSource] = useState(document?.source ?? '');
    const [number, setNumber] = useState(document?.number ?? '');
    const [date, setDate] = useState(document?.issued_on ?? today);
    const [amount, setAmount] = useState(
        document?.amount_minor ? minorToText(document.amount_minor) : '',
    );
    const [file, setFile] = useState<File | null>(null);
    const tax = settlement.customer_tax;

    const submit = async () => {
        if (!request && !file) {
            call.setError(t('invoices.link.file_required'));

            return;
        }
        const form = new FormData();
        form.append('idempotency_key', key);
        if (document) {
            form.append('lock_version', String(document.lock_version));
        } else {
            form.append('kind', kind);
            form.append('event', event);
            if (event === 'payment') {
                form.append('payment_id', link);
            }
            if (event === 'refund') {
                form.append('refund_id', link);
            }
        }
        if (!request) {
            form.append('source', source);
            form.append('number', number);
            form.append('issued_on', date);
            form.append('amount', amount);
            form.append('file', file as File);
        }
        const data = await call.run(url, form);
        if (data) {
            onDone(data);
        }
    };
    const links =
        event === 'payment'
            ? settlement.payments.map((p) => ({
                  value: String(p.id),
                  label: paymentLabel(p),
              }))
            : settlement.refunds
                  .filter((r) => r.number)
                  .map((r) => ({ value: String(r.id), label: r.number ?? '' }));

    return (
        <Shell
            test="document-link-dialog"
            title={
                request
                    ? t('invoices.request.title', { order: orderNumber })
                    : t('invoices.link.title', { order: orderNumber })
            }
            description={t('invoices.link.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-document-link"
                >
                    {request
                        ? t('invoices.request.confirm')
                        : t('invoices.link.confirm')}
                </Button>
            }
        >
            {kind === 'tax_invoice' && (
                <Alert
                    variant={
                        tax.missing.includes('vat_number') ? 'warning' : 'info'
                    }
                >
                    <AlertTitle>
                        {t('invoices.customer.title', { name: tax.name })}
                    </AlertTitle>
                    <AlertBody>
                        {t('invoices.customer.vat')}:{' '}
                        <bdi dir="ltr">{tax.vat_number ?? '—'}</bdi> ·{' '}
                        {t('invoices.customer.cr')}:{' '}
                        <bdi dir="ltr">
                            {tax.commercial_registration ?? '—'}
                        </bdi>
                        {tax.missing.length > 0 && (
                            <span className="block">
                                {t('invoices.customer.missing', {
                                    fields: tax.missing
                                        .map((m) =>
                                            t(
                                                `invoices.customer.field.${m}` as TranslationKey,
                                            ),
                                        )
                                        .join('، '),
                                })}
                            </span>
                        )}
                    </AlertBody>
                </Alert>
            )}
            {!document && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label={t('invoices.field.kind')}
                        value={kind}
                        required
                        onChange={(e) => setKind(e.target.value)}
                        options={DOCUMENT_KINDS.map((k) => ({
                            value: k,
                            label: t(`invoices.kind.${k}` as TranslationKey),
                        }))}
                    />
                    <SelectField
                        label={t('invoices.field.event')}
                        value={event}
                        required
                        onChange={(e) => {
                            setEvent(e.target.value);
                            setLink(
                                e.target.value === 'refund'
                                    ? String(
                                          settlement.refunds.find(
                                              (r) => r.number,
                                          )?.id ?? '',
                                      )
                                    : String(settlement.payments[0]?.id ?? ''),
                            );
                        }}
                        options={DOCUMENT_EVENTS.map((v) => ({
                            value: v,
                            label: t(`invoices.event.${v}` as TranslationKey),
                        }))}
                    />
                    {(event === 'payment' || event === 'refund') && (
                        <SelectField
                            label={t('invoices.field.link')}
                            value={link}
                            required
                            error={
                                call.fieldError('payment_id') ??
                                call.fieldError('refund_id')
                            }
                            onChange={(e) => setLink(e.target.value)}
                            options={links}
                        />
                    )}
                </div>
            )}
            {document?.correction_reason && (
                <Alert variant="warning">
                    <AlertBody>{document.correction_reason}</AlertBody>
                </Alert>
            )}
            {!request && (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={t('invoices.field.source')}
                            hint={t('invoices.field.source_hint')}
                            value={source}
                            required
                            error={call.fieldError('source')}
                            onChange={(e) => setSource(e.target.value)}
                        />
                        <TextField
                            label={t('invoices.field.number')}
                            dir="ltr"
                            value={number}
                            required
                            error={call.fieldError('number')}
                            onChange={(e) => setNumber(e.target.value)}
                            data-test="document-number"
                        />
                        <TextField
                            label={t('invoices.field.date')}
                            type="date"
                            dir="ltr"
                            max={today}
                            value={date}
                            required
                            error={call.fieldError('issued_on')}
                            onChange={(e) => setDate(e.target.value)}
                        />
                        <TextField
                            label={t('invoices.field.amount')}
                            inputMode="decimal"
                            dir="ltr"
                            value={amount}
                            required
                            error={call.fieldError('amount')}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </div>
                    <FileField
                        label={t('invoices.field.file')}
                        required
                        error={call.fieldError('file')}
                        onChange={setFile}
                    />
                </>
            )}
            <Notes
                items={[
                    t('invoices.link.note_compliance'),
                    t('invoices.link.note_unique'),
                ]}
            />
        </Shell>
    );
}

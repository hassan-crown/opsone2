import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    bpToPercent,
    minorToText,
    newKey,
    toMajor,
} from '@/components/agreement/money';
import { Amount } from '@/components/opsone/amount';
import {
    CheckboxField,
    Field,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    FinanceDue,
    FinancePayload,
    FinancePayment,
    FinanceProof,
} from './types';
import { useFinanceCall } from './use-finance-call';

type Done = (finance: FinancePayload) => void;

const METHODS = ['transfer', 'cash', 'card', 'cheque'] as const;

/** `12.50` → 1250 without floats; null when not a valid amount. */
export function textToMinor(text: string): number | null {
    const match = /^(\d{1,12})(?:[.,](\d{1,2}))?$/.exec(text.trim());
    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

export function Shell({
    title,
    description,
    children,
    footer,
    error,
    onClose,
    test,
    size = 'md',
}: {
    title: ReactNode;
    description: ReactNode;
    children?: ReactNode;
    footer: ReactNode;
    error: string | null;
    onClose: () => void;
    test: string;
    size?: 'sm' | 'md' | 'lg';
}) {
    const t = useT();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size={size} data-test={test}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pe-1">
                    {children}
                    {error && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{error}</AlertBody>
                        </Alert>
                    )}
                </div>
                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('ui.cancel')}
                    </Button>
                    {footer}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Notes({ items }: { items: string[] }) {
    return (
        <ul className="grid list-disc gap-1 ps-5 text-body text-weak">
            {items.map((item) => (
                <li key={item}>{item}</li>
            ))}
        </ul>
    );
}

function FileInput({
    label,
    hint,
    error,
    required = true,
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
                    data-test="evidence-input"
                />
            )}
        </Field>
    );
}

/** FinRecordPayment: confirmed at once with a numbered receipt — no second approval (FIN-04). */
export function RecordPaymentDialog({
    url,
    orderNumber,
    lockVersion,
    defaultMinor,
    dueName,
    customerOrders,
    today,
    cashProofRequired = true,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    lockVersion: number;
    defaultMinor: number;
    dueName: string | null;
    customerOrders: { id: number; number: string }[];
    today: string;
    cashProofRequired?: boolean;
    onClose: () => void;
    onDone: (finance: FinancePayload, receipt: string | null) => void;
}) {
    const t = useT();
    const call = useFinanceCall<{
        finance: FinancePayload;
        receipt: string | null;
    }>();
    const [key] = useState(newKey);
    const [amount, setAmount] = useState(
        defaultMinor > 0 ? minorToText(defaultMinor) : '',
    );
    const [method, setMethod] = useState<string>('transfer');
    const [date, setDate] = useState(today);
    const [reference, setReference] = useState('');
    const [note, setNote] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [splitOrder, setSplitOrder] = useState('');
    const [splitAmount, setSplitAmount] = useState('');
    const [acknowledge, setAcknowledge] = useState(false);

    // Cash without a file only when the owner allowed it; the note then says why (ADR-0014).
    const proofOptional = method === 'cash' && !cashProofRequired;
    const submit = async () => {
        if (!file && !proofOptional) {
            call.setError(t('finance.record.evidence_required'));

            return;
        }
        if (!file && note.trim() === '') {
            call.setError(t('finance.record.cash_note_required'));

            return;
        }
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('lock_version', String(lockVersion));
        form.append('amount', amount);
        form.append('method', method);
        form.append('paid_on', date);
        form.append('reference', reference);
        form.append('note', note);
        if (splitOrder && splitAmount) {
            form.append('split[0][order_id]', splitOrder);
            form.append('split[0][amount]', splitAmount);
        }
        if (acknowledge) {
            form.append('acknowledge_similar', '1');
        }
        if (file) {
            form.append('evidence', file);
        }
        const data = await call.run('POST', url, form);
        if (data) {
            onDone(data.finance, data.receipt);
        }
    };

    return (
        <Shell
            test="record-payment-dialog"
            title={t('finance.record.title', { order: orderNumber })}
            description={t('finance.record.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-record-payment"
                >
                    {t('finance.record.confirm')}
                </Button>
            }
        >
            <TextField
                label={t('finance.field.amount')}
                hint={
                    dueName
                        ? t('finance.record.allocated_to', { due: dueName })
                        : t('finance.record.allocated_oldest')
                }
                inputMode="decimal"
                dir="ltr"
                value={amount}
                required
                error={call.fieldError('amount')}
                onChange={(e) => setAmount(e.target.value)}
                data-test="payment-amount"
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    label={t('finance.field.method')}
                    value={method}
                    required
                    error={call.fieldError('method')}
                    onChange={(e) => setMethod(e.target.value)}
                    options={METHODS.map((m) => ({
                        value: m,
                        label: t(`finance.method.${m}` as TranslationKey),
                    }))}
                />
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
            </div>
            <TextField
                label={t('finance.field.reference')}
                hint={t('finance.record.reference_hint')}
                dir="ltr"
                value={reference}
                required={method === 'transfer' || method === 'cheque'}
                error={call.fieldError('reference')}
                onChange={(e) => setReference(e.target.value)}
                data-test="payment-reference"
            />
            <FileInput
                label={t('finance.field.evidence')}
                hint={
                    proofOptional
                        ? t('finance.record.cash_proof_optional')
                        : t('finance.record.evidence_hint')
                }
                required={!proofOptional}
                error={call.fieldError('evidence') ?? call.fieldError('file')}
                onChange={setFile}
            />
            {customerOrders.length > 0 && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label={t('finance.record.split_order')}
                        hint={t('finance.record.split_hint')}
                        value={splitOrder}
                        placeholder={t('finance.record.split_none')}
                        onChange={(e) => setSplitOrder(e.target.value)}
                        options={customerOrders.map((o) => ({
                            value: String(o.id),
                            label: o.number,
                        }))}
                    />
                    <TextField
                        label={t('finance.record.split_amount')}
                        inputMode="decimal"
                        dir="ltr"
                        value={splitAmount}
                        disabled={!splitOrder}
                        error={
                            call.fieldError('split') ??
                            call.fieldError('split.0.amount')
                        }
                        onChange={(e) => setSplitAmount(e.target.value)}
                    />
                </div>
            )}
            <TextareaField
                label={t('finance.field.note')}
                value={note}
                required={proofOptional && !file}
                error={call.fieldError('note')}
                onChange={(e) => setNote(e.target.value)}
            />
            {call.condition === 'similar_payment' && (
                <CheckboxField
                    label={t('finance.record.acknowledge_similar')}
                    checked={acknowledge}
                    onChange={(e) => setAcknowledge(e.target.checked)}
                    data-test="acknowledge-similar"
                />
            )}
            <Notes
                items={[
                    t('finance.record.note_receipt'),
                    t('finance.record.note_same_transfer'),
                ]}
            />
        </Shell>
    );
}

/** FinReceiptReview / FinReceiptMobile: confirm the amount received — linked to the same transfer if already recorded. */
export function ReviewProofDialog({
    proof,
    url,
    onClose,
    onCorrection,
    onDone,
}: {
    proof: FinanceProof;
    url: string;
    onClose: () => void;
    onCorrection: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall();
    const [key] = useState(newKey);
    const [amount, setAmount] = useState(
        proof.claimed_minor !== undefined
            ? minorToText(proof.claimed_minor)
            : '',
    );
    const [date, setDate] = useState(proof.paid_on);
    const [reference, setReference] = useState(proof.reference ?? '');
    const [acknowledge, setAcknowledge] = useState(false);

    const submit = async () => {
        const data = await call.run('POST', url, {
            idempotency_key: key,
            lock_version: proof.lock_version,
            amount,
            paid_on: date,
            reference,
            acknowledge_similar: acknowledge,
        });
        if (data) {
            onDone(data.finance);
        }
    };

    return (
        <Shell
            test="review-proof-dialog"
            title={t('finance.review.title')}
            description={
                proof.claimed_minor !== undefined ? (
                    <>
                        {t('finance.review.claimed')}{' '}
                        <Amount value={toMajor(proof.claimed_minor)} strong />
                    </>
                ) : (
                    t('finance.review.description')
                )
            }
            error={call.error}
            onClose={onClose}
            footer={
                <>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onCorrection}
                        data-test="request-correction"
                    >
                        {t('finance.review.request_correction')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        loading={call.busy}
                        data-test="confirm-proof"
                    >
                        {t('finance.review.confirm')}
                    </Button>
                </>
            }
        >
            {proof.files.map((file) =>
                file.url ? (
                    <a
                        key={file.uuid}
                        href={file.url}
                        target="_blank"
                        rel="noreferrer"
                        className="text-link underline"
                    >
                        {file.name}
                    </a>
                ) : null,
            )}
            {proof.matching_receipt && (
                <Alert variant="info">
                    <AlertBody>
                        {t('finance.review.matching', {
                            receipt: proof.matching_receipt,
                        })}
                    </AlertBody>
                </Alert>
            )}
            {proof.similar && !proof.matching_receipt && (
                <Alert variant="warning">
                    <AlertBody>{t('finance.review.similar')}</AlertBody>
                </Alert>
            )}
            <TextField
                label={t('finance.review.received')}
                hint={t('finance.review.received_hint')}
                inputMode="decimal"
                dir="ltr"
                value={amount}
                required
                error={call.fieldError('amount')}
                onChange={(e) => setAmount(e.target.value)}
                data-test="proof-amount"
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label={t('finance.field.paid_on')}
                    type="date"
                    dir="ltr"
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
            {call.condition === 'similar_payment' && (
                <CheckboxField
                    label={t('finance.record.acknowledge_similar')}
                    checked={acknowledge}
                    onChange={(e) => setAcknowledge(e.target.checked)}
                />
            )}
            <Notes
                items={[
                    t('finance.review.note_receipt'),
                    t('finance.review.note_difference'),
                ]}
            />
        </Shell>
    );
}

/** FinReceiptFix / cancel: a reason the customer can read; nothing is collected and the file stays. */
export function FinanceReasonDialog({
    title,
    description,
    confirm,
    url,
    lockVersion,
    destructive,
    onClose,
    onDone,
}: {
    title: string;
    description: string;
    confirm: string;
    url: string;
    lockVersion: number;
    destructive?: boolean;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall();
    const [reason, setReason] = useState('');

    const submit = async () => {
        const data = await call.run('POST', url, {
            lock_version: lockVersion,
            reason,
        });
        if (data) {
            onDone(data.finance);
        }
    };

    return (
        <Shell
            test="finance-reason-dialog"
            size="sm"
            title={title}
            description={description}
            error={call.fieldError('reason') ? null : call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    variant={destructive ? 'destructive' : 'default'}
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-finance-reason"
                >
                    {confirm}
                </Button>
            }
        >
            <TextareaField
                label={t('finance.field.reason')}
                value={reason}
                required
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
        </Shell>
    );
}

type Row = {
    key: string;
    reference: string;
    ar: string;
    en: string;
    basis: 'amount' | 'percent' | 'rest';
    value: string;
    event: 'agreement' | 'payment_stage' | 'delivery' | 'date';
    due_date: string;
};

let rowSequence = 0;

function rowOf(due: FinanceDue): Row {
    return {
        key: `d${++rowSequence}`,
        reference: due.reference,
        ar: due.name_i18n.ar ?? '',
        en: due.name_i18n.en ?? '',
        basis: due.basis,
        value:
            due.basis === 'amount'
                ? minorToText(due.basis_value ?? 0)
                : due.basis === 'percent'
                  ? bpToPercent(due.basis_value ?? 0)
                  : '',
        event: due.event,
        due_date: due.due_date ?? '',
    };
}

/** Client preview of the server's amounts (integer halalas, half-up percentages, rest takes what is left). */
function preview(rows: Row[], agreed: number): (number | null)[] {
    const amounts: (number | null)[] = rows.map((r) => {
        if (r.basis === 'amount') {
            return textToMinor(r.value);
        }
        if (r.basis === 'percent') {
            const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(r.value.trim());
            if (!match) {
                return null;
            }
            const bp =
                Number(match[1]) * 100 +
                Number((match[2] ?? '').padEnd(2, '0'));

            return Math.floor((agreed * bp + 5000) / 10000);
        }

        return 0;
    });
    const fixed = amounts.reduce<number>((s, a) => s + (a ?? 0), 0);

    return rows.map((r, i) =>
        r.basis === 'rest' ? Math.max(0, agreed - fixed) : amounts[i],
    );
}

/** FinSchedule (P-38): the sum never exceeds the agreement; a paid due never changes silently (checked on the server). */
export function ScheduleDialog({
    url,
    lockVersion,
    dues,
    agreedMinor,
    onClose,
    onDone,
}: {
    url: string;
    lockVersion: number;
    dues: FinanceDue[];
    agreedMinor: number | null;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall();
    const [key] = useState(newKey);
    const [rows, setRows] = useState<Row[]>(() => dues.map(rowOf));
    const agreed = agreedMinor ?? 0;
    const amounts = preview(rows, agreed);
    const total = amounts.reduce<number>((s, a) => s + (a ?? 0), 0);

    const update = (index: number, patch: Partial<Row>) =>
        setRows(rows.map((r, i) => (i === index ? { ...r, ...patch } : r)));
    const template = (name: 'half' | 'single') =>
        setRows(
            name === 'half'
                ? [
                      {
                          key: `d${++rowSequence}`,
                          reference: 'first',
                          ar: 'الدفعة الأولى',
                          en: 'First payment',
                          basis: 'percent',
                          value: '50',
                          event: 'agreement',
                          due_date: '',
                      },
                      {
                          key: `d${++rowSequence}`,
                          reference: 'final',
                          ar: 'الدفعة الأخيرة',
                          en: 'Final payment',
                          basis: 'rest',
                          value: '',
                          event: 'payment_stage',
                          due_date: '',
                      },
                  ]
                : [
                      {
                          key: `d${++rowSequence}`,
                          reference: 'first',
                          ar: 'كامل المبلغ',
                          en: 'Full amount',
                          basis: 'rest',
                          value: '',
                          event: 'agreement',
                          due_date: '',
                      },
                  ],
        );

    const submit = async () => {
        const data = await call.run('PUT', url, {
            idempotency_key: key,
            lock_version: lockVersion,
            dues: rows.map((r) => ({
                reference: r.reference,
                name: { ar: r.ar, en: r.en || null },
                basis: r.basis,
                value: r.basis === 'rest' ? null : r.value,
                event: r.event,
                due_date: r.event === 'date' ? r.due_date : null,
            })),
        });
        if (data) {
            onDone(data.finance);
        }
    };

    return (
        <Shell
            test="schedule-dialog"
            size="lg"
            title={t('finance.schedule.title')}
            description={t('finance.schedule.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    disabled={total > agreed}
                    data-test="save-schedule"
                >
                    {t('finance.schedule.save')}
                </Button>
            }
        >
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={() => template('half')}
                >
                    {t('finance.schedule.template.half')}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={() => template('single')}
                >
                    {t('finance.schedule.template.single')}
                </Button>
            </div>
            {rows.map((row, i) => (
                <fieldset
                    key={row.key}
                    className="grid gap-3 rounded-md border border-stroke-weak p-3 sm:grid-cols-2"
                    data-test="schedule-row"
                >
                    <TextField
                        label={t('finance.schedule.name')}
                        value={row.ar}
                        required
                        error={call.fieldError(`dues.${i}.name.ar`)}
                        onChange={(e) => update(i, { ar: e.target.value })}
                    />
                    <TextField
                        label={t('finance.schedule.name_en')}
                        dir="ltr"
                        value={row.en}
                        onChange={(e) => update(i, { en: e.target.value })}
                    />
                    <SelectField
                        label={t('finance.schedule.basis')}
                        value={row.basis}
                        onChange={(e) =>
                            update(i, { basis: e.target.value as Row['basis'] })
                        }
                        options={(['amount', 'percent', 'rest'] as const).map(
                            (b) => ({
                                value: b,
                                label: t(
                                    `finance.basis.${b}` as TranslationKey,
                                ),
                            }),
                        )}
                    />
                    <TextField
                        label={t('finance.schedule.value')}
                        dir="ltr"
                        inputMode="decimal"
                        value={row.value}
                        disabled={row.basis === 'rest'}
                        error={call.fieldError(`dues.${i}.value`)}
                        onChange={(e) => update(i, { value: e.target.value })}
                    />
                    <SelectField
                        label={t('finance.schedule.event')}
                        value={row.event}
                        onChange={(e) =>
                            update(i, { event: e.target.value as Row['event'] })
                        }
                        options={(
                            ['agreement', 'payment_stage', 'delivery', 'date'] as const
                        ).map((ev) => ({
                            value: ev,
                            label: t(`finance.event.${ev}` as TranslationKey),
                        }))}
                    />
                    {row.event === 'date' ? (
                        <TextField
                            label={t('finance.schedule.date')}
                            type="date"
                            dir="ltr"
                            value={row.due_date}
                            required
                            error={call.fieldError(`dues.${i}.due_date`)}
                            onChange={(e) =>
                                update(i, { due_date: e.target.value })
                            }
                        />
                    ) : (
                        <TextField
                            label={t('finance.schedule.reference')}
                            hint={t('finance.schedule.reference_hint')}
                            dir="ltr"
                            value={row.reference}
                            error={call.fieldError(`dues.${i}.reference`)}
                            onChange={(e) =>
                                update(i, { reference: e.target.value })
                            }
                        />
                    )}
                    <div className="flex items-center justify-between gap-2 sm:col-span-2">
                        <span className="text-weak">
                            {t('finance.field.amount')}:{' '}
                            {amounts[i] === null ? (
                                '—'
                            ) : (
                                <Amount
                                    value={toMajor(amounts[i] ?? 0)}
                                    strong
                                />
                            )}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setRows(rows.filter((_, j) => j !== i))
                            }
                        >
                            {t('finance.schedule.remove')}
                        </Button>
                    </div>
                </fieldset>
            ))}
            <Button
                type="button"
                variant="secondary"
                onClick={() =>
                    setRows([
                        ...rows,
                        {
                            key: `d${++rowSequence}`,
                            reference: `due_${rows.length + 1}`,
                            ar: '',
                            en: '',
                            basis: 'amount',
                            value: '',
                            event: 'date',
                            due_date: '',
                        },
                    ])
                }
                disabled={rows.length >= 12}
            >
                {t('finance.schedule.add')}
            </Button>
            <dl
                className="grid gap-1 border-t border-stroke-weak pt-3"
                data-test="schedule-totals"
            >
                <div className="flex justify-between gap-2">
                    <dt className="text-weak">{t('finance.schedule.sum')}</dt>
                    <dd>
                        <Amount value={toMajor(total)} strong />
                    </dd>
                </div>
                <div className="flex justify-between gap-2">
                    <dt className="text-weak">{t('finance.summary.agreed')}</dt>
                    <dd>
                        <Amount value={toMajor(agreed)} />
                    </dd>
                </div>
                <div className="flex justify-between gap-2">
                    <dt className="text-weak">
                        {t('finance.schedule.difference')}
                    </dt>
                    <dd className={total > agreed ? 'text-danger-text' : ''}>
                        <Amount value={toMajor(agreed - total)} />
                    </dd>
                </div>
            </dl>
            <Notes
                items={[
                    t('finance.schedule.note_paid'),
                    t('finance.schedule.note_rounding'),
                ]}
            />
        </Shell>
    );
}

/** FinAllocate: credit of this order to another order of the same customer; no new receipt. */
export function TransferDialog({
    url,
    orderNumber,
    lockVersion,
    creditMinor,
    customerOrders,
    onClose,
    onDone,
}: {
    url: string;
    orderNumber: string;
    lockVersion: number;
    creditMinor: number;
    customerOrders: { id: number; number: string }[];
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall();
    const [key] = useState(newKey);
    const [target, setTarget] = useState(
        customerOrders[0] ? String(customerOrders[0].id) : '',
    );
    const [amount, setAmount] = useState(minorToText(creditMinor));
    const [reason, setReason] = useState('');

    const submit = async () => {
        const data = await call.run('POST', url, {
            idempotency_key: key,
            lock_version: lockVersion,
            to_order_id: Number(target),
            amount,
            reason,
        });
        if (data) {
            onDone(data.finance);
        }
    };

    return (
        <Shell
            test="transfer-dialog"
            title={t('finance.transfer.title', { order: orderNumber })}
            description={
                <>
                    {t('finance.transfer.description')}{' '}
                    <Amount value={toMajor(creditMinor)} strong />
                </>
            }
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    disabled={!target}
                    data-test="confirm-transfer"
                >
                    {t('finance.transfer.confirm')}
                </Button>
            }
        >
            <SelectField
                label={t('finance.transfer.to')}
                value={target}
                required
                error={call.fieldError('to_order_id')}
                onChange={(e) => setTarget(e.target.value)}
                options={customerOrders.map((o) => ({
                    value: String(o.id),
                    label: o.number,
                }))}
            />
            <TextField
                label={t('finance.field.amount')}
                inputMode="decimal"
                dir="ltr"
                value={amount}
                required
                error={call.fieldError('amount')}
                onChange={(e) => setAmount(e.target.value)}
            />
            <TextareaField
                label={t('finance.field.reason')}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
            />
            <Notes items={[t('finance.transfer.note')]} />
        </Shell>
    );
}

/** FIN-06 review: how one payment's share on the order is spread over the dues. */
export function ReallocateDialog({
    url,
    orderId,
    lockVersion,
    payment,
    dues,
    onClose,
    onDone,
}: {
    url: string;
    orderId: number;
    lockVersion: number;
    payment: FinancePayment;
    dues: FinanceDue[];
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall();
    const [key] = useState(newKey);
    const [values, setValues] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            dues.map((d) => [
                d.id,
                minorToText(
                    payment.allocations?.find((a) => a.due_id === d.id)
                        ?.amount_minor ?? 0,
                ),
            ]),
        ),
    );
    const placed = dues.reduce(
        (s, d) => s + (textToMinor(values[d.id] ?? '') ?? 0),
        0,
    );
    const share = payment.share_minor ?? 0;

    const submit = async () => {
        const data = await call.run('POST', url, {
            idempotency_key: key,
            lock_version: lockVersion,
            order_id: orderId,
            allocations: dues.map((d) => ({
                due_id: d.id,
                amount: values[d.id] || '0',
            })),
        });
        if (data) {
            onDone(data.finance);
        }
    };

    return (
        <Shell
            test="reallocate-dialog"
            title={t('finance.reallocate.title', {
                receipt: payment.receipt_number ?? '',
            })}
            description={t('finance.reallocate.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    disabled={placed > share}
                    data-test="confirm-reallocate"
                >
                    {t('finance.reallocate.confirm')}
                </Button>
            }
        >
            {dues.map((due) => (
                <TextField
                    key={due.id}
                    label={due.name ?? due.reference}
                    hint={
                        due.remaining_minor !== undefined
                            ? t('finance.reallocate.lacking', {
                                  amount: minorToText(due.remaining_minor),
                              })
                            : undefined
                    }
                    inputMode="decimal"
                    dir="ltr"
                    value={values[due.id] ?? ''}
                    onChange={(e) =>
                        setValues({ ...values, [due.id]: e.target.value })
                    }
                />
            ))}
            <p className="text-weak">
                {t('finance.reallocate.placed')}{' '}
                <Amount value={toMajor(placed)} strong /> /{' '}
                <Amount value={toMajor(share)} />
            </p>
        </Shell>
    );
}

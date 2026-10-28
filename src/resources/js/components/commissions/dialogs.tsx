import { useState } from 'react';
import PayoutController from '@/actions/App/Http/Controllers/Commissions/PayoutController';
import { newKey } from '@/components/agreement/money';
import { Shell, textToMinor } from '@/components/finance/dialogs';
import { useFinanceCall } from '@/components/finance/use-finance-call';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { FileInput, Minor } from './parts';
import type { Payout, Statement } from './types';

type Done = (statement: Statement) => void;
type Response = { payout_id: number; statement: Statement };

const METHODS = ['transfer', 'cash', 'cheque', 'payroll'] as const;

function Execution({
    value,
    onChange,
    fieldError,
    onFile,
}: {
    value: { method: string; paid_on: string; reference: string; note: string };
    onChange: (next: {
        method: string;
        paid_on: string;
        reference: string;
        note: string;
    }) => void;
    fieldError: (field: string) => string | undefined;
    onFile: (file: File | null) => void;
}) {
    const t = useT();

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    label={t('commissions.payout.method')}
                    value={value.method}
                    required
                    options={METHODS.map((m) => ({
                        value: m,
                        label: t(`commissions.method.${m}` as TranslationKey),
                    }))}
                    error={fieldError('method')}
                    onChange={(e) =>
                        onChange({ ...value, method: e.target.value })
                    }
                />
                <TextField
                    label={t('commissions.payout.paid_on')}
                    type="date"
                    dir="ltr"
                    required
                    value={value.paid_on}
                    error={fieldError('paid_on')}
                    onChange={(e) =>
                        onChange({ ...value, paid_on: e.target.value })
                    }
                />
            </div>
            <TextField
                label={t('commissions.payout.reference')}
                dir="ltr"
                required
                value={value.reference}
                error={fieldError('reference')}
                onChange={(e) =>
                    onChange({ ...value, reference: e.target.value })
                }
                data-test="payout-reference"
            />
            <TextareaField
                label={t('commissions.payout.note')}
                optional
                value={value.note}
                error={fieldError('note')}
                onChange={(e) => onChange({ ...value, note: e.target.value })}
            />
            <FileInput
                label={t('commissions.payout.proof')}
                error={fieldError('proof')}
                onChange={onFile}
            />
        </>
    );
}

function form(
    fields: Record<string, string | number | boolean | null>,
    file: File | null,
): FormData {
    const data = new FormData();
    Object.entries(fields).forEach(([k, v]) => {
        if (v !== null) {
            data.append(
                k,
                typeof v === 'boolean' ? (v ? '1' : '0') : String(v),
            );
        }
    });
    if (file) {
        data.append('proof', file);
    }

    return data;
}

/**
 * FinPayout (SAL-14, M-33-T01+T02): the member's available balance is taken at once and recorded as paid with a
 * method, date, reference and proof. The server derives the amount again under locks; held orders stay out.
 */
export function PayoutDialog({
    statement,
    draft,
    onClose,
    onDone,
}: {
    statement: Statement;
    draft: Payout | null;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall<Response>();
    const [keys] = useState(() => ({ create: newKey(), approve: newKey() }));
    const [file, setFile] = useState<File | null>(null);
    const [execution, setExecution] = useState({
        method: 'transfer',
        paid_on: statement.today,
        reference: '',
        note: '',
    });
    const payable = statement.payable;

    const submit = async () => {
        let payout = draft;
        if (!payout) {
            const created = await call.run(
                'POST',
                PayoutController.store.url(statement.member.id),
                {
                    idempotency_key: keys.create,
                    period_from: `${statement.today.slice(0, 8)}01`,
                    period_to: statement.today,
                },
            );
            if (!created) {
                return;
            }
            payout =
                created.statement.payouts.find(
                    (p) => p.id === created.payout_id,
                ) ?? null;
            if (!payout) {
                return;
            }
        }
        const data = await call.run(
            'POST',
            PayoutController.approve.url(payout.id),
            form(
                {
                    idempotency_key: keys.approve,
                    lock_version: payout.lock_version,
                    pay_now: true,
                    ...execution,
                },
                file,
            ),
        );
        if (data) {
            onDone(data.statement);
        }
    };

    return (
        <Shell
            test="payout-dialog"
            title={t('commissions.payout.title', {
                name: statement.member.name,
            })}
            description={t('commissions.payout.description')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    disabled={!payable || payable.amount_minor <= 0}
                    data-test="record-payout"
                >
                    {t('commissions.payout.record')}
                </Button>
            }
        >
            <dl className="bg-surface-sunken grid grid-cols-3 gap-3 rounded-md p-3">
                <div>
                    <dt className="text-caption text-weak">
                        {t('commissions.col.accrued')}
                    </dt>
                    <dd>
                        <Minor value={statement.summary.accrued_minor} />
                    </dd>
                </div>
                <div>
                    <dt className="text-caption text-weak">
                        {t('commissions.payout.paid_before')}
                    </dt>
                    <dd>
                        <Minor value={statement.summary.paid_minor} />
                    </dd>
                </div>
                <div>
                    <dt className="text-caption text-weak">
                        {t('commissions.col.available')}
                    </dt>
                    <dd data-test="payout-amount">
                        <Minor value={payable?.amount_minor ?? 0} strong />
                    </dd>
                </div>
            </dl>
            {payable && payable.held.length > 0 && (
                <p className="text-caption text-weak">
                    {t('commissions.payout.held_out', {
                        count: payable.held.length,
                    })}
                </p>
            )}
            <Execution
                value={execution}
                onChange={setExecution}
                fieldError={call.fieldError}
                onFile={setFile}
            />
            <ul className="grid list-disc gap-1 ps-5 text-caption text-weak">
                <li>{t('commissions.payout.note_recheck')}</li>
                <li>{t('commissions.payout.note_negative')}</li>
            </ul>
        </Shell>
    );
}

/** M-33-T02 for a payout reserved earlier. */
export function RecordDialog({
    payout,
    today,
    onClose,
    onDone,
}: {
    payout: Payout;
    today: string;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall<Response>();
    const [key] = useState(newKey);
    const [file, setFile] = useState<File | null>(null);
    const [execution, setExecution] = useState({
        method: 'transfer',
        paid_on: today,
        reference: '',
        note: '',
    });

    const submit = async () => {
        const data = await call.run(
            'POST',
            PayoutController.record.url(payout.id),
            form(
                {
                    idempotency_key: key,
                    lock_version: payout.lock_version,
                    ...execution,
                },
                file,
            ),
        );
        if (data) {
            onDone(data.statement);
        }
    };

    return (
        <Shell
            test="record-dialog"
            title={t('commissions.payout.record')}
            description={<Minor value={payout.amount_minor} strong />}
            error={call.error}
            onClose={onClose}
            footer={
                <Button type="button" onClick={submit} loading={call.busy}>
                    {t('commissions.payout.record')}
                </Button>
            }
        >
            <Execution
                value={execution}
                onChange={setExecution}
                fieldError={call.fieldError}
                onFile={setFile}
            />
        </Shell>
    );
}

/** M-33-T03: withdraw a draft or reserved payout with a reason; nothing was paid. */
export function CancelDialog({
    payout,
    onClose,
    onDone,
}: {
    payout: Payout;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall<Response>();
    const [reason, setReason] = useState('');

    const submit = async () => {
        const data = await call.run(
            'POST',
            PayoutController.cancel.url(payout.id),
            { reason, lock_version: payout.lock_version },
        );
        if (data) {
            onDone(data.statement);
        }
    };

    return (
        <Shell
            test="cancel-payout-dialog"
            title={t('commissions.payout.cancel')}
            description={t('commissions.payout.cancel_hint')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    variant="destructive"
                    onClick={submit}
                    loading={call.busy}
                >
                    {t('commissions.payout.cancel')}
                </Button>
            }
        >
            <TextareaField
                label={t('commissions.reason')}
                required
                value={reason}
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
        </Shell>
    );
}

/** M-33-T04 (P-97): what really left, with a reason and a proof; the original stays. */
export function CorrectDialog({
    payout,
    onClose,
    onDone,
}: {
    payout: Payout;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useFinanceCall<Response>();
    const [key] = useState(newKey);
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [file, setFile] = useState<File | null>(null);

    const submit = async () => {
        if (textToMinor(amount) === null) {
            call.setError(t('commissions.validation.amount'));

            return;
        }
        const data = await call.run(
            'POST',
            PayoutController.correct.url(payout.id),
            form(
                {
                    idempotency_key: key,
                    lock_version: payout.lock_version,
                    amount: amount.replace(',', '.'),
                    reason,
                },
                file,
            ),
        );
        if (data) {
            onDone(data.statement);
        }
    };

    return (
        <Shell
            test="correct-payout-dialog"
            title={t('commissions.payout.correct')}
            description={t('commissions.payout.correct_hint')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button type="button" onClick={submit} loading={call.busy}>
                    {t('commissions.payout.correct')}
                </Button>
            }
        >
            <p className="text-body">
                {t('commissions.payout.recorded')}:{' '}
                <Minor value={payout.amount_minor} strong />
            </p>
            <TextField
                label={t('commissions.payout.actual')}
                inputMode="decimal"
                dir="ltr"
                required
                value={amount}
                error={call.fieldError('amount')}
                onChange={(e) => setAmount(e.target.value)}
            />
            <TextareaField
                label={t('commissions.reason')}
                required
                value={reason}
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
            <FileInput
                label={t('commissions.payout.proof')}
                error={call.fieldError('proof')}
                onChange={setFile}
            />
        </Shell>
    );
}

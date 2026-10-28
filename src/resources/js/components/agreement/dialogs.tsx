import { useState } from 'react';
import type { ReactNode } from 'react';
import { Amount } from '@/components/opsone/amount';
import {
    CheckboxField,
    Field,
    RadioGroup,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
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
import { formatDate, formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { newKey, toMajor } from './money';
import { DocumentStatusBadge } from './status';
import { TotalsSummary } from './summary';
import type {
    AgreementDocument,
    AgreementPayload,
    PricedContent,
} from './types';
import { useAgreementCall } from './use-agreement-call';

type Done = (payload: AgreementPayload) => void;

const CHANNELS = ['message', 'phone', 'paper', 'in_person', 'email', 'other'];

function Shell({
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

/** AgrSend: fixes the content; the customer approves from their account; sending is not approval. */
export function SendDialog({
    document,
    customer,
    totalMinor,
    url,
    defaultDays,
    onClose,
    onDone,
}: {
    document: AgreementDocument;
    customer: string;
    totalMinor: number | null | undefined;
    url: string;
    defaultDays: number;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useAgreementCall();
    const [key] = useState(newKey);
    const [days, setDays] = useState(String(defaultDays));
    const expires = document.kind === 'quote';

    const send = async () => {
        const data = await call.run('POST', url, {
            idempotency_key: key,
            lock_version: document.lock_version,
            validity_days: expires ? Number(days) : null,
        });
        if (data) {
            onDone(data.agreement);
        }
    };

    return (
        <Shell
            test="send-dialog"
            title={t('agreements.send.title', { number: document.number })}
            description={
                <>
                    {customer}
                    {totalMinor !== null && totalMinor !== undefined && (
                        <>
                            {' · '}
                            <Amount value={toMajor(totalMinor)} />
                        </>
                    )}
                </>
            }
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={send}
                    loading={call.busy}
                    data-test="confirm-send"
                >
                    {t('agreements.send.confirm')}
                </Button>
            }
        >
            {expires && (
                <TextField
                    label={t('agreements.send.validity')}
                    value={days}
                    inputMode="numeric"
                    dir="ltr"
                    error={call.fieldError('validity_days')}
                    onChange={(e) => setDays(e.target.value)}
                />
            )}
            <Notes
                items={[
                    t('agreements.send.note_fixed'),
                    t('agreements.send.note_account'),
                    t('agreements.send.note_not_approval'),
                ]}
            />
        </Shell>
    );
}

/** AgrExternal / AgrExternalChange: an approval given outside OpsOne, with channel, date and evidence (Q-40 a). */
export function ExternalApprovalDialog({
    title,
    summary,
    afterNotes,
    contentHash,
    lockVersion,
    url,
    onClose,
    onDone,
}: {
    title: string;
    summary: ReactNode;
    afterNotes: string[];
    contentHash: string;
    lockVersion: number;
    url: string;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useAgreementCall();
    const [key] = useState(newKey);
    const [channel, setChannel] = useState('message');
    const [date, setDate] = useState(() =>
        new Date().toISOString().slice(0, 10),
    );
    const [note, setNote] = useState('');
    const [file, setFile] = useState<File | null>(null);

    const submit = async () => {
        if (!file) {
            call.setError(t('agreements.external.evidence_required'));

            return;
        }
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('lock_version', String(lockVersion));
        form.append('channel', channel);
        form.append('decided_on', date);
        form.append('note', note);
        form.append('content_hash', contentHash);
        form.append('evidence', file);
        const data = await call.run('POST', url, form);
        if (data) {
            onDone(data.agreement);
        }
    };

    return (
        <Shell
            test="external-approval-dialog"
            title={title}
            description={summary}
            error={call.error}
            onClose={onClose}
            footer={
                <Button
                    type="button"
                    onClick={submit}
                    loading={call.busy}
                    data-test="confirm-external"
                >
                    {t('agreements.external.confirm')}
                </Button>
            }
        >
            <SelectField
                label={t('agreements.external.channel')}
                value={channel}
                required
                error={call.fieldError('channel')}
                onChange={(e) => setChannel(e.target.value)}
                options={CHANNELS.map((c) => ({
                    value: c,
                    label: t(`agreements.channel.${c}` as TranslationKey),
                }))}
            />
            <TextField
                label={t('agreements.external.date')}
                type="date"
                dir="ltr"
                value={date}
                required
                error={call.fieldError('decided_on')}
                onChange={(e) => setDate(e.target.value)}
            />
            <Field
                label={t('agreements.external.evidence')}
                hint={t('agreements.external.evidence_hint')}
                error={call.fieldError('evidence') ?? call.fieldError('file')}
                required
            >
                {(control) => (
                    <input
                        {...control}
                        type="file"
                        className="field-control py-2"
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        data-test="evidence-input"
                    />
                )}
            </Field>
            <TextareaField
                label={t('agreements.external.note')}
                value={note}
                onChange={(e) => setNote(e.target.value)}
            />
            <Notes items={afterNotes} />
        </Shell>
    );
}

/** AgrDesignUpload: the design file, the lines it concerns and the text the customer sees. Uploading is not approval. */
export function DesignDialog({
    orderNumber,
    lines,
    url,
    replaces,
    sendUrl,
    onClose,
    onDone,
}: {
    orderNumber: string;
    lines: { id: number; name: string }[];
    url: string;
    replaces: AgreementDocument | null;
    sendUrl: (documentId: number) => string;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useAgreementCall<{
        document_id: number;
        agreement: AgreementPayload;
    }>();
    const send = useAgreementCall();
    const [key] = useState(newKey);
    const [sendKey] = useState(newKey);
    const [description, setDescription] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [file, setFile] = useState<File | null>(null);

    const submit = async (andSend: boolean) => {
        if (!file) {
            call.setError(t('agreements.design.file_required'));

            return;
        }
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('file', file);
        form.append('description', description);
        selected.forEach((id) => form.append('lines[]', String(id)));
        if (replaces) {
            form.append('replaces_id', String(replaces.id));
        }
        const data = await call.run('POST', url, form);
        if (!data) {
            return;
        }
        if (andSend) {
            const created = data.agreement.documents.find(
                (d) => d.id === data.document_id,
            );
            const sent = await send.run('POST', sendUrl(data.document_id), {
                idempotency_key: sendKey,
                lock_version: created?.lock_version ?? 1,
            });
            onDone(sent ? sent.agreement : data.agreement);

            return;
        }
        onDone(data.agreement);
    };

    return (
        <Shell
            test="design-dialog"
            title={t('agreements.design.title', { number: orderNumber })}
            description={
                replaces
                    ? t('agreements.design.replaces', {
                          number: replaces.number,
                      })
                    : t('agreements.design.hint')
            }
            error={call.error ?? send.error}
            onClose={onClose}
            footer={
                <>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => submit(false)}
                        loading={call.busy && !send.busy}
                    >
                        {t('agreements.design.save')}
                    </Button>
                    <Button
                        type="button"
                        onClick={() => submit(true)}
                        loading={send.busy}
                        data-test="design-save-send"
                    >
                        {t('agreements.design.save_send')}
                    </Button>
                </>
            }
        >
            <Field
                label={t('agreements.design.file')}
                error={call.fieldError('file')}
                required
            >
                {(control) => (
                    <input
                        {...control}
                        type="file"
                        className="field-control py-2"
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    />
                )}
            </Field>
            <TextareaField
                label={t('agreements.design.description')}
                value={description}
                required
                error={call.fieldError('description')}
                onChange={(e) => setDescription(e.target.value)}
            />
            {lines.length > 0 && (
                <fieldset className="m-0 grid gap-1 border-0 p-0">
                    <legend className="mb-1 text-label text-strong">
                        {t('agreements.design.lines')}
                    </legend>
                    {lines.map((line) => (
                        <CheckboxField
                            key={line.id}
                            label={line.name}
                            checked={selected.includes(line.id)}
                            onChange={(e) =>
                                setSelected((all) =>
                                    e.target.checked
                                        ? [...all, line.id]
                                        : all.filter((id) => id !== line.id),
                                )
                            }
                        />
                    ))}
                </fieldset>
            )}
            <Notes
                items={[
                    t('agreements.design.note_need'),
                    t('agreements.design.note_not_approval'),
                ]}
            />
        </Shell>
    );
}

/** AgrContract: from the approved agreement (no re-typing) or an external contract file (Q-36 c, no templates). */
export function ContractDialog({
    orderNumber,
    hasAgreement,
    url,
    onClose,
    onDone,
}: {
    orderNumber: string;
    hasAgreement: boolean;
    url: string;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useAgreementCall();
    const [key] = useState(newKey);
    const [source, setSource] = useState(hasAgreement ? 'agreement' : 'file');
    const [terms, setTerms] = useState('');
    const [file, setFile] = useState<File | null>(null);

    const submit = async () => {
        const form = new FormData();
        form.append('idempotency_key', key);
        form.append('terms', terms);
        if (source === 'file') {
            if (!file) {
                call.setError(t('agreements.contract.file_required'));

                return;
            }
            form.append('file', file);
        }
        const data = await call.run('POST', url, form);
        if (data) {
            onDone(data.agreement);
        }
    };

    return (
        <Shell
            test="contract-dialog"
            title={t('agreements.contract.title', { number: orderNumber })}
            description={t('agreements.contract.hint')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button type="button" onClick={submit} loading={call.busy}>
                    {t('agreements.contract.confirm')}
                </Button>
            }
        >
            <RadioGroup
                legend={t('agreements.contract.source')}
                name="contract-source"
                value={source}
                onChange={setSource}
                options={[
                    {
                        value: 'agreement',
                        label: t('agreements.contract.from_agreement'),
                        disabled: !hasAgreement,
                    },
                    {
                        value: 'file',
                        label: t('agreements.contract.from_file'),
                    },
                ]}
            />
            {source === 'file' && (
                <Field
                    label={t('agreements.contract.file')}
                    error={call.fieldError('file')}
                    required
                >
                    {(control) => (
                        <input
                            {...control}
                            type="file"
                            className="field-control py-2"
                            onChange={(e) =>
                                setFile(e.target.files?.[0] ?? null)
                            }
                        />
                    )}
                </Field>
            )}
            <TextareaField
                label={t('agreements.contract.terms')}
                value={terms}
                onChange={(e) => setTerms(e.target.value)}
            />
            <Notes
                items={[
                    t('agreements.contract.note_snapshot'),
                    t('agreements.contract.note_approval'),
                ]}
            />
        </Shell>
    );
}

/** Cancel, withdraw, revision request: a written reason, then the write. */
export function ReasonDialog({
    title,
    description,
    label,
    confirm,
    url,
    lockVersion,
    destructive,
    onClose,
    onDone,
}: {
    title: string;
    description: string;
    label: string;
    confirm: string;
    url: string;
    lockVersion: number;
    destructive?: boolean;
    onClose: () => void;
    onDone: Done;
}) {
    const call = useAgreementCall();
    const [reason, setReason] = useState('');

    const submit = async () => {
        const data = await call.run('POST', url, {
            lock_version: lockVersion,
            reason,
        });
        if (data) {
            onDone(data.agreement);
        }
    };

    return (
        <Shell
            test="reason-dialog"
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
                    data-test="confirm-reason"
                >
                    {confirm}
                </Button>
            }
        >
            <TextareaField
                label={label}
                value={reason}
                required
                error={call.fieldError('reason')}
                onChange={(e) => setReason(e.target.value)}
            />
        </Shell>
    );
}

/** M-08-T06: same content, a new validity period. */
export function ExtendDialog({
    document,
    url,
    defaultDays,
    onClose,
    onDone,
}: {
    document: AgreementDocument;
    url: string;
    defaultDays: number;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useAgreementCall();
    const [days, setDays] = useState(String(defaultDays));

    const submit = async () => {
        const data = await call.run('POST', url, {
            lock_version: document.lock_version,
            validity_days: Number(days),
        });
        if (data) {
            onDone(data.agreement);
        }
    };

    return (
        <Shell
            test="extend-dialog"
            size="sm"
            title={t('agreements.extend.title', { number: document.number })}
            description={t('agreements.extend.hint')}
            error={call.error}
            onClose={onClose}
            footer={
                <Button type="button" onClick={submit} loading={call.busy}>
                    {t('agreements.extend.confirm')}
                </Button>
            }
        >
            <TextField
                label={t('agreements.send.validity')}
                value={days}
                inputMode="numeric"
                dir="ltr"
                error={call.fieldError('validity_days')}
                onChange={(e) => setDays(e.target.value)}
            />
        </Shell>
    );
}

/** One line from a party block of a contract (missing data is shown, never blocking). */
function Party({
    title,
    data,
    missing,
    prefix,
}: {
    title: string;
    data: Record<string, string | null>;
    missing: string[];
    prefix: string;
}) {
    const t = useT();

    return (
        <div className="rounded-md p-3 ring-1 ring-stroke-weak">
            <h4 className="mb-2 font-semibold text-strong">{title}</h4>
            <dl className="grid gap-1">
                {Object.entries(data).map(([field, value]) => (
                    <div
                        key={field}
                        className="flex flex-wrap justify-between gap-2"
                    >
                        <dt className="text-weak">
                            {t(`agreements.party.${field}` as TranslationKey)}
                        </dt>
                        <dd className="text-strong">
                            {value ??
                                (missing.includes(`${prefix}.${field}`) ? (
                                    <span className="text-warning-text">
                                        {t('agreements.party.missing')}
                                    </span>
                                ) : (
                                    '—'
                                ))}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** AgrDocument: the sent content as the customer sees it; opening it is never approval. */
export function DocumentView({
    document,
    amountsVisible,
    onClose,
}: {
    document: AgreementDocument;
    amountsVisible: boolean;
    onClose: () => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const content = document.content;
    const priced =
        document.kind === 'quote'
            ? (content as unknown as PricedContent)
            : null;
    const contract = document.kind === 'contract' ? content : null;
    const parties = contract?.parties as
        | {
              facility: Record<string, string | null>;
              customer: Record<string, string | null>;
          }
        | undefined;
    const missing = (contract?.missing as string[] | undefined) ?? [];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size="lg" data-test="document-view">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            `agreements.kind.${document.kind}` as TranslationKey,
                        )}{' '}
                        <bdi dir="ltr">{document.number}</bdi>
                    </DialogTitle>
                    <DialogDescription>
                        {document.sent_at
                            ? t('agreements.view.fixed')
                            : t('agreements.view.draft')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pe-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <DocumentStatusBadge status={document.status} />
                        {document.valid_until && (
                            <span className="text-weak">
                                {t('agreements.view.valid_until', {
                                    date: formatDate(
                                        document.valid_until,
                                        locale,
                                    ),
                                })}
                            </span>
                        )}
                        {document.sent_at && (
                            <span className="text-weak">
                                {t('agreements.view.sent', {
                                    name: document.sent_by ?? '',
                                    date: formatDateTime(
                                        document.sent_at,
                                        locale,
                                    ),
                                })}
                            </span>
                        )}
                    </div>

                    {typeof content.description === 'string' && (
                        <p className="text-body text-strong">
                            {content.description}
                        </p>
                    )}

                    {parties && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Party
                                title={t('agreements.party.facility')}
                                data={parties.facility}
                                missing={missing}
                                prefix="facility"
                            />
                            <Party
                                title={t('agreements.party.customer')}
                                data={parties.customer}
                                missing={missing}
                                prefix="customer"
                            />
                        </div>
                    )}

                    {priced && (
                        <LinesTable
                            content={priced}
                            amountsVisible={amountsVisible}
                        />
                    )}
                    {priced?.terms && (
                        <div>
                            <h4 className="mb-1 font-semibold text-strong">
                                {t('agreements.quote.terms')}
                            </h4>
                            <p className="text-body whitespace-pre-line text-weak">
                                {priced.terms}
                            </p>
                        </div>
                    )}
                    {priced && amountsVisible && (
                        <TotalsSummary
                            totals={priced.totals}
                            discount={priced.discount}
                            vat={priced.vat}
                        />
                    )}
                    {Array.isArray(contract?.clauses) && (
                        <ol className="grid list-decimal gap-1 ps-5 text-body text-strong">
                            {(contract.clauses as string[]).map((clause) => (
                                <li key={clause}>{clause}</li>
                            ))}
                        </ol>
                    )}

                    {document.files.length > 0 && (
                        <ul className="grid gap-1">
                            {document.files.map((file) => (
                                <li key={file.uuid}>
                                    {file.url ? (
                                        <a
                                            className="text-link underline"
                                            href={file.url}
                                        >
                                            {file.name}
                                        </a>
                                    ) : (
                                        file.name
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {document.decision && (
                        <Alert
                            variant={
                                document.decision.decision === 'approved'
                                    ? 'success'
                                    : 'info'
                            }
                        >
                            <AlertBody>
                                <DecisionText decision={document.decision} />
                            </AlertBody>
                        </Alert>
                    )}
                    {document.status === 'awaiting_customer' && (
                        <p className="text-caption text-weak">
                            {t('agreements.view.open_not_approval')}
                        </p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function DecisionText({
    decision,
}: {
    decision: NonNullable<AgreementDocument['decision']>;
}) {
    const t = useT();
    const { locale } = useLocale();
    // An external approval carries the day the customer approved; the account approval its exact time.
    const date =
        decision.method === 'external'
            ? formatDate(decision.decided_at, locale)
            : formatDateTime(decision.decided_at, locale);

    return (
        <>
            <p>
                {decision.decision === 'changes_requested'
                    ? t('agreements.decision.changes_requested', { date })
                    : decision.method === 'external'
                      ? t('agreements.decision.external', {
                            name: decision.recorded_by ?? '',
                            channel: t(
                                `agreements.channel.${decision.channel ?? 'other'}` as TranslationKey,
                            ),
                            date,
                        })
                      : t('agreements.decision.account', { date })}
            </p>
            {decision.note && <p className="text-weak">{decision.note}</p>}
            {decision.evidence.map((file) =>
                file.url ? (
                    <a
                        key={file.uuid}
                        className="text-link underline"
                        href={file.url}
                    >
                        {t('agreements.decision.evidence', { name: file.name })}
                    </a>
                ) : null,
            )}
        </>
    );
}

/** Lines of priced content; prices only for viewers who may see amounts. */
export function LinesTable({
    content,
    amountsVisible,
    compareTo,
}: {
    content: PricedContent;
    amountsVisible: boolean;
    compareTo?: PricedContent;
}) {
    const t = useT();

    return (
        <TableWrap>
            <Table caption={t('agreements.quote.lines')} className="min-w-0">
                <thead>
                    <tr>
                        <Th>{t('agreements.quote.line_description')}</Th>
                        <Th>{t('agreements.quote.quantity')}</Th>
                        {amountsVisible && (
                            <Th>{t('agreements.quote.unit_price')}</Th>
                        )}
                        {amountsVisible && (
                            <Th>{t('agreements.quote.line_total')}</Th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {content.lines.map((line, i) => {
                        const before = compareTo?.lines[i];
                        const changed =
                            compareTo !== undefined &&
                            (before === undefined ||
                                before.description !== line.description ||
                                before.quantity !== line.quantity ||
                                before.unit_price_minor !==
                                    line.unit_price_minor);

                        return (
                            <tr
                                key={`${i}-${line.description}`}
                                data-changed={changed || undefined}
                            >
                                <Td
                                    className={
                                        changed ? 'bg-warning-bg' : undefined
                                    }
                                >
                                    {line.description}
                                    {changed && (
                                        <span className="ms-2 text-caption text-warning-text">
                                            {t('agreements.change.changed')}
                                        </span>
                                    )}
                                </Td>
                                <Td>
                                    <bdi dir="ltr">{line.quantity}</bdi>{' '}
                                    {line.unit ?? ''}
                                </Td>
                                {amountsVisible && (
                                    <Td>
                                        {line.unit_price_minor === null ||
                                        line.unit_price_minor === undefined ? (
                                            <span className="text-weak">
                                                {t('agreements.amount.unknown')}
                                            </span>
                                        ) : (
                                            <Amount
                                                value={toMajor(
                                                    line.unit_price_minor,
                                                )}
                                            />
                                        )}
                                    </Td>
                                )}
                                {amountsVisible && (
                                    <Td>
                                        {line.gross_minor === undefined ? (
                                            <span className="text-weak">
                                                {t('agreements.amount.unknown')}
                                            </span>
                                        ) : (
                                            <Amount
                                                value={toMajor(
                                                    line.gross_minor,
                                                )}
                                            />
                                        )}
                                    </Td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </Table>
        </TableWrap>
    );
}

/** Create a change request: a short description; lines start from the agreement (AgrChange). */
export function CreateChangeDialog({
    url,
    onClose,
    onDone,
}: {
    url: string;
    onClose: () => void;
    onDone: Done;
}) {
    const t = useT();
    const call = useAgreementCall();
    const [key] = useState(newKey);
    const [description, setDescription] = useState('');

    const submit = async () => {
        const data = await call.run('POST', url, {
            idempotency_key: key,
            description,
        });
        if (data) {
            onDone(data.agreement);
        }
    };

    return (
        <Shell
            test="create-change-dialog"
            size="sm"
            title={t('agreements.change.create')}
            description={t('agreements.change.create_hint')}
            error={call.fieldError('description') ? null : call.error}
            onClose={onClose}
            footer={
                <Button type="button" onClick={submit} loading={call.busy}>
                    {t('agreements.change.create_confirm')}
                </Button>
            }
        >
            <TextareaField
                label={t('agreements.change.description')}
                value={description}
                required
                error={call.fieldError('description')}
                onChange={(e) => setDescription(e.target.value)}
            />
        </Shell>
    );
}

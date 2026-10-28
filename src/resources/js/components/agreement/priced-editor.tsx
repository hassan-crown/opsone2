import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    CheckboxField,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Amount } from '@/components/opsone/amount';
import { useT } from '@/lib/i18n';
import {
    bpToPercent,
    editableLines,
    minorToText,
    newKey,
    newLine,
    toMajor,
} from './money';
import { TotalsSummary } from './summary';
import type {
    AgreementPayload,
    DiscountState,
    EditableLine,
    FlowActionOption,
    PricedContent,
} from './types';
import { useAgreementCall } from './use-agreement-call';
import type { AgreementResponse } from './use-agreement-call';

type ChangeFields = {
    description: string;
    affected: string[];
    delay_days: number;
};

/**
 * AgrQuote / AgrQuoteApproved and AgrChange: lines from the order (only what pricing needs), discount, terms and
 * execution days; for a change also its description, the affected work and the extra days. The server prices
 * everything (Q-34/Q-35) and checks the discount limit; the summary shows its numbers after each save.
 */
export function PricedEditor({
    mode,
    number,
    content,
    discount,
    lockVersion,
    saveUrl,
    discountUrl,
    change,
    actions,
    amountsVisible,
    onClose,
    onSaved,
}: {
    mode: 'quote' | 'change';
    number: string;
    content: PricedContent;
    discount: DiscountState | null;
    lockVersion: number;
    saveUrl: string;
    discountUrl: string;
    change?: ChangeFields;
    actions?: FlowActionOption[];
    amountsVisible: boolean;
    onClose: () => void;
    onSaved: (payload: AgreementPayload) => void;
}) {
    const t = useT();
    const call = useAgreementCall();
    const request = useAgreementCall();
    const [lines, setLines] = useState<EditableLine[]>(() =>
        editableLines(content),
    );
    const [discountType, setDiscountType] = useState(
        content.discount?.type ?? 'none',
    );
    const [discountValue, setDiscountValue] = useState(() =>
        content.discount?.type === 'amount'
            ? minorToText(content.discount.value)
            : content.discount?.type === 'percent'
              ? bpToPercent(content.discount.value)
              : '',
    );
    const [terms, setTerms] = useState(content.terms ?? '');
    const [days, setDays] = useState(
        content.execution_days === null ? '' : String(content.execution_days),
    );
    const [fields, setFields] = useState<ChangeFields>(
        change ?? { description: '', affected: [], delay_days: 0 },
    );
    const [reason, setReason] = useState('');
    const [saveKey, setSaveKey] = useState(newKey);
    const [requestKey] = useState(newKey);

    const update = (key: string, patch: Partial<EditableLine>) =>
        setLines((all) =>
            all.map((l) => (l.key === key ? { ...l, ...patch } : l)),
        );

    const save = async () => {
        const body: Record<string, unknown> = {
            idempotency_key: saveKey,
            lock_version: lockVersion,
            lines: lines.map(({ key: _key, ...line }) => ({
                ...line,
                unit: line.unit === '' ? null : line.unit,
                unit_price: line.unit_price === '' ? null : line.unit_price,
            })),
            discount: {
                type: discountType,
                value: discountType === 'none' ? null : discountValue,
            },
            terms: terms === '' ? null : terms,
            execution_days: days === '' ? null : Number(days),
            ...(mode === 'change' ? fields : {}),
        };
        const data = await call.run('PUT', saveUrl, body);
        if (data) {
            setSaveKey(newKey());
            onSaved(data.agreement);
        }
    };

    const requestDecision = async () => {
        const data = await request.run('POST', discountUrl, {
            idempotency_key: requestKey,
            lock_version: lockVersion,
            reason,
        });
        if (data) {
            onSaved((data as AgreementResponse).agreement);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size="lg" data-test="priced-editor">
                <DialogHeader>
                    <DialogTitle>
                        {mode === 'quote'
                            ? t('agreements.quote.title', { number })
                            : t('agreements.change.edit_title', { number })}
                    </DialogTitle>
                    <DialogDescription>
                        {mode === 'quote'
                            ? t('agreements.quote.hint')
                            : t('agreements.change.hint')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid max-h-[65vh] gap-6 overflow-y-auto pe-1">
                    {mode === 'change' && (
                        <TextareaField
                            label={t('agreements.change.description')}
                            value={fields.description}
                            required
                            error={call.fieldError('description')}
                            onChange={(e) =>
                                setFields({
                                    ...fields,
                                    description: e.target.value,
                                })
                            }
                        />
                    )}

                    <section className="grid gap-3" aria-labelledby="lines-h">
                        <h3 id="lines-h" className="text-subtitle text-strong">
                            {t('agreements.quote.lines')}
                        </h3>
                        {lines.map((line, i) => (
                            <div
                                key={line.key}
                                className="grid gap-3 rounded-md p-3 ring-1 ring-stroke-weak sm:grid-cols-12"
                                data-test="quote-line"
                            >
                                <TextField
                                    className="sm:col-span-12"
                                    label={t(
                                        'agreements.quote.line_description',
                                    )}
                                    value={line.description}
                                    required
                                    error={call.fieldError(
                                        `lines.${i}.description`,
                                    )}
                                    onChange={(e) =>
                                        update(line.key, {
                                            description: e.target.value,
                                        })
                                    }
                                />
                                <TextField
                                    className="sm:col-span-3"
                                    label={t('agreements.quote.quantity')}
                                    value={line.quantity}
                                    inputMode="decimal"
                                    dir="ltr"
                                    required
                                    error={call.fieldError(
                                        `lines.${i}.quantity`,
                                    )}
                                    onChange={(e) =>
                                        update(line.key, {
                                            quantity: e.target.value,
                                        })
                                    }
                                />
                                <TextField
                                    className="sm:col-span-3"
                                    label={t('agreements.quote.unit')}
                                    value={line.unit}
                                    onChange={(e) =>
                                        update(line.key, {
                                            unit: e.target.value,
                                        })
                                    }
                                />
                                <TextField
                                    className="sm:col-span-4"
                                    label={t('agreements.quote.unit_price')}
                                    value={line.unit_price}
                                    inputMode="decimal"
                                    dir="ltr"
                                    error={call.fieldError(
                                        `lines.${i}.unit_price`,
                                    )}
                                    onChange={(e) =>
                                        update(line.key, {
                                            unit_price: e.target.value,
                                        })
                                    }
                                />
                                <div className="flex items-end justify-end sm:col-span-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        aria-label={t(
                                            'agreements.quote.remove_line',
                                        )}
                                        onClick={() =>
                                            setLines((all) =>
                                                all.filter(
                                                    (l) => l.key !== line.key,
                                                ),
                                            )
                                        }
                                    >
                                        <Icon icon={Trash2} />
                                    </Button>
                                </div>
                                <CheckboxField
                                    className="sm:col-span-12"
                                    label={t('agreements.quote.commissionable')}
                                    checked={line.commissionable}
                                    onChange={(e) =>
                                        update(line.key, {
                                            commissionable: e.target.checked,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        {call.fieldError('lines') && (
                            <p className="text-danger-text" role="alert">
                                {call.fieldError('lines')}
                            </p>
                        )}
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    setLines((all) => [...all, newLine()])
                                }
                            >
                                <Icon icon={Plus} />
                                {t('agreements.quote.add_line')}
                            </Button>
                        </div>
                    </section>

                    <section className="grid gap-3 sm:grid-cols-2">
                        <SelectField
                            label={t('agreements.quote.discount_type')}
                            value={discountType}
                            onChange={(e) =>
                                setDiscountType(
                                    e.target.value as
                                        | 'none'
                                        | 'percent'
                                        | 'amount',
                                )
                            }
                            options={(
                                ['none', 'percent', 'amount'] as const
                            ).map((v) => ({
                                value: v,
                                label: t(`agreements.quote.discount.${v}`),
                            }))}
                        />
                        {discountType !== 'none' && (
                            <TextField
                                label={
                                    discountType === 'percent'
                                        ? t('agreements.quote.discount_percent')
                                        : t('agreements.quote.discount_amount')
                                }
                                value={discountValue}
                                inputMode="decimal"
                                dir="ltr"
                                error={call.fieldError('discount.value')}
                                onChange={(e) =>
                                    setDiscountValue(e.target.value)
                                }
                            />
                        )}
                    </section>

                    {discount && discount.state !== 'none' && (
                        <DiscountPanel
                            discount={discount}
                            amountsVisible={amountsVisible}
                            reason={reason}
                            onReason={setReason}
                            onRequest={requestDecision}
                            busy={request.busy}
                            error={
                                request.error ?? request.fieldError('reason')
                            }
                        />
                    )}

                    <section className="grid gap-3 sm:grid-cols-2">
                        <TextareaField
                            className="sm:col-span-2"
                            label={t('agreements.quote.terms')}
                            value={terms}
                            onChange={(e) => setTerms(e.target.value)}
                        />
                        <TextField
                            label={t('agreements.quote.execution_days')}
                            value={days}
                            inputMode="numeric"
                            dir="ltr"
                            error={call.fieldError('execution_days')}
                            onChange={(e) => setDays(e.target.value)}
                        />
                        {mode === 'change' && (
                            <TextField
                                label={t('agreements.change.delay_days')}
                                value={String(fields.delay_days)}
                                inputMode="numeric"
                                dir="ltr"
                                error={call.fieldError('delay_days')}
                                onChange={(e) =>
                                    setFields({
                                        ...fields,
                                        delay_days: Number(e.target.value) || 0,
                                    })
                                }
                            />
                        )}
                    </section>

                    {mode === 'change' && actions && actions.length > 0 && (
                        <fieldset className="m-0 grid gap-1 border-0 p-0">
                            <legend className="mb-1 text-label text-strong">
                                {t('agreements.change.affected')}
                            </legend>
                            <p className="text-caption text-weak">
                                {t('agreements.change.affected_hint')}
                            </p>
                            <div className="grid gap-x-4 sm:grid-cols-2">
                                {actions.map((action) => (
                                    <CheckboxField
                                        key={action.key}
                                        label={action.name}
                                        checked={fields.affected.includes(
                                            action.key,
                                        )}
                                        onChange={(e) =>
                                            setFields({
                                                ...fields,
                                                affected: e.target.checked
                                                    ? [
                                                          ...fields.affected,
                                                          action.key,
                                                      ]
                                                    : fields.affected.filter(
                                                          (k) =>
                                                              k !== action.key,
                                                      ),
                                            })
                                        }
                                    />
                                ))}
                            </div>
                        </fieldset>
                    )}

                    {amountsVisible && (
                        <section>
                            <h3 className="mb-2 text-subtitle text-strong">
                                {t('agreements.quote.summary')}
                            </h3>
                            <p className="mb-2 text-caption text-weak">
                                {content.vat.inclusive
                                    ? t('agreements.vat.inclusive')
                                    : t('agreements.vat.exclusive')}
                            </p>
                            <TotalsSummary
                                totals={content.totals}
                                discount={content.discount}
                                vat={content.vat}
                            />
                        </section>
                    )}

                    {call.error && !call.fieldError('lines') && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{call.error}</AlertBody>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('ui.close')}
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        loading={call.busy}
                        data-test="save-draft"
                    >
                        {t('agreements.quote.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Limit check of the priced draft (AGR-02): within the limit, or a referral to a supervisor in scope. */
function DiscountPanel({
    discount,
    amountsVisible,
    reason,
    onReason,
    onRequest,
    busy,
    error,
}: {
    discount: DiscountState;
    amountsVisible: boolean;
    reason: string;
    onReason: (value: string) => void;
    onRequest: () => void;
    busy: boolean;
    error: string | null | undefined;
}) {
    const t = useT();
    const limit =
        discount.limit_bp === null
            ? t('agreements.discount.no_limit')
            : `${bpToPercent(discount.limit_bp)}%`;

    if (discount.state === 'incomplete') {
        return null;
    }
    if (discount.state === 'within_limit' || discount.state === 'approved') {
        return (
            <Alert variant="success" data-test="discount-ok">
                <AlertBody>
                    {discount.state === 'approved'
                        ? t('agreements.discount.approved', {
                              name: discount.decision?.decider ?? '',
                          })
                        : t('agreements.discount.within', { limit })}
                </AlertBody>
            </Alert>
        );
    }
    if (discount.state === 'requested') {
        return (
            <Alert variant="info" data-test="discount-requested">
                <AlertBody>{t('agreements.discount.requested')}</AlertBody>
            </Alert>
        );
    }

    return (
        <Alert variant="warning" data-test="discount-needs-decision">
            <AlertBody>
                <AlertTitle>
                    {t('agreements.discount.above', {
                        share: bpToPercent(discount.share_bp),
                        limit,
                    })}
                </AlertTitle>
                {amountsVisible && (
                    <p>
                        {t('agreements.discount.over_by')}{' '}
                        <Amount value={toMajor(discount.over_minor)} />
                    </p>
                )}
                <p>{t('agreements.discount.referral')}</p>
                <TextareaField
                    className="mt-3"
                    label={t('agreements.discount.reason')}
                    value={reason}
                    error={error ?? undefined}
                    onChange={(e) => onReason(e.target.value)}
                />
                <Button
                    type="button"
                    className="mt-3"
                    onClick={onRequest}
                    loading={busy}
                    data-test="request-discount"
                >
                    {t('agreements.discount.request')}
                </Button>
            </AlertBody>
        </Alert>
    );
}

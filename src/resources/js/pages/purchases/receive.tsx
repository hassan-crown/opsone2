import { Head, Link, useForm } from '@inertiajs/react';
import { TriangleAlert, X } from 'lucide-react';
import { useRef } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { formatQty, parseQty } from '@/components/procurement/quantity';
import {
    BackLink,
    FormErrors,
    PurchaseStatusBadge,
    Qty,
    unitLabel,
    useIdempotencyKey,
} from '@/components/procurement/shared';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { LocationOption, PurchaseDetail } from '@/types/procurement';

type Props = { purchase: PurchaseDetail; locations: LocationOption[] };

type Entry = {
    line_id: number;
    accepted: string;
    rejected: string;
    reject_reason: string;
};

const MAX_PROOFS = 5;

function today(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export default function PurchaseReceive({ purchase, locations }: Props) {
    const t = useT();
    const { key, renew } = useIdempotencyKey();
    const fileInput = useRef<HTMLInputElement>(null);
    const open = purchase.lines.filter((l) => l.remaining_milli > 0);
    const form = useForm({
        location_id:
            purchase.location_id !== null ? String(purchase.location_id) : '',
        received_on: today(),
        reference: '',
        note: '',
        lines: open.map((l): Entry => ({
            line_id: l.id,
            accepted: '',
            rejected: '',
            reject_reason: '',
        })),
        proofs: [] as File[],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setEntry = (index: number, patch: Partial<Entry>) =>
        form.setData(
            'lines',
            form.data.lines.map((e, i) =>
                i === index ? { ...e, ...patch } : e,
            ),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            location_id:
                data.location_id === '' ? null : Number(data.location_id),
            received_on: data.received_on,
            reference: data.reference,
            note: data.note,
            lines: data.lines.map((e) => ({
                line_id: e.line_id,
                accepted: e.accepted.trim(),
                rejected: e.rejected.trim(),
                reject_reason:
                    (parseQty(e.rejected) ?? 0) > 0
                        ? e.reject_reason.trim()
                        : null,
            })),
            ...(data.proofs.length > 0 ? { proofs: data.proofs } : {}),
            idempotency_key: key,
            lock_version: purchase.lock_version,
        }));
        form.post(PurchaseController.receive.url({ purchase: purchase.id }), {
            preserveScroll: true,
            forceFormData: true,
            onError: renew,
        });
    };

    const addFiles = (list: FileList | null) => {
        if (!list) {
            return;
        }
        form.setData(
            'proofs',
            [...form.data.proofs, ...Array.from(list)].slice(0, MAX_PROOFS),
        );
        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const proofError =
        errors.proofs ??
        Object.entries(errors).find(([k]) => k.startsWith('proofs.'))?.[1];
    const showUrl = PurchaseController.show.url({ purchase: purchase.id });

    return (
        <>
            <Head
                title={t('procurement.receive_title', {
                    number: purchase.number,
                })}
            />
            <PageHeader
                title={t('procurement.receive_title', {
                    number: purchase.number,
                })}
                description={purchase.supplier.name.label}
                back={<BackLink href={showUrl} label={purchase.number} />}
                actions={<PurchaseStatusBadge status={purchase.status} />}
            />
            <form
                onSubmit={submit}
                data-test="receive-form"
                className="grid gap-4"
            >
                <FormErrors errors={errors} />
                <Card className="gap-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        {locations.length > 0 ? (
                            <SelectField
                                label={t('procurement.location')}
                                placeholder={t('procurement.choose')}
                                value={form.data.location_id}
                                onChange={(e) =>
                                    form.setData('location_id', e.target.value)
                                }
                                options={locations.map((l) => ({
                                    value: String(l.id),
                                    label: l.name,
                                }))}
                                required
                                error={errors.location_id}
                            />
                        ) : (
                            <TextField
                                label={t('procurement.location_id')}
                                type="number"
                                min={1}
                                inputMode="numeric"
                                dir="ltr"
                                value={form.data.location_id}
                                readOnly={purchase.location_id !== null}
                                onChange={(e) =>
                                    form.setData('location_id', e.target.value)
                                }
                                required
                                error={errors.location_id}
                            />
                        )}
                        <TextField
                            label={t('procurement.received_on')}
                            type="date"
                            dir="ltr"
                            max={today()}
                            value={form.data.received_on}
                            onChange={(e) =>
                                form.setData('received_on', e.target.value)
                            }
                            required
                            error={errors.received_on}
                        />
                        <TextField
                            label={t('procurement.delivery_note')}
                            hint={t('procurement.delivery_note_hint')}
                            dir="ltr"
                            maxLength={80}
                            value={form.data.reference}
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                            optional
                            error={errors.reference}
                        />
                    </div>
                    <TextareaField
                        label={t('procurement.receipt_note')}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        optional
                        error={errors.note}
                    />
                </Card>

                <Card className="gap-4">
                    <h2 className="text-heading text-strong">
                        {t('procurement.items')}
                    </h2>
                    <ul className="grid gap-4">
                        {open.map((l, index) => {
                            const entry = form.data.lines[index];
                            const accepted = parseQty(entry.accepted);
                            const rejected = parseQty(entry.rejected);
                            const valid =
                                accepted !== null && rejected !== null;
                            const over = valid && accepted > l.remaining_milli;
                            const unit = unitLabel(l.unit, t);

                            return (
                                <li
                                    key={l.id}
                                    data-test="receive-line"
                                    className="grid gap-3 rounded-md border border-stroke-weak p-3"
                                >
                                    <div>
                                        <p className="font-semibold text-strong">
                                            {l.name.label}
                                        </p>
                                        <p className="text-caption text-weak">
                                            {t('procurement.col_ordered')}{' '}
                                            <Qty
                                                milli={l.quantity_milli}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t(
                                                'procurement.accepted_before',
                                            )}{' '}
                                            <Qty
                                                milli={l.accepted_milli}
                                                unit={l.unit}
                                            />
                                            {' · '}
                                            {t(
                                                'procurement.col_remaining',
                                            )}{' '}
                                            <Qty
                                                milli={l.remaining_milli}
                                                unit={l.unit}
                                            />
                                        </p>
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <TextField
                                            label={`${t('procurement.col_accepted')} (${unit})`}
                                            inputMode="decimal"
                                            dir="ltr"
                                            value={entry.accepted}
                                            onChange={(e) =>
                                                setEntry(index, {
                                                    accepted: e.target.value,
                                                })
                                            }
                                            error={
                                                errors[
                                                    `lines.${index}.accepted`
                                                ] ??
                                                (accepted === null
                                                    ? t(
                                                          'procurement.error.quantity',
                                                      )
                                                    : undefined)
                                            }
                                        />
                                        <TextField
                                            label={`${t('procurement.col_rejected')} (${unit})`}
                                            inputMode="decimal"
                                            dir="ltr"
                                            value={entry.rejected}
                                            onChange={(e) =>
                                                setEntry(index, {
                                                    rejected: e.target.value,
                                                })
                                            }
                                            error={
                                                errors[
                                                    `lines.${index}.rejected`
                                                ] ??
                                                (rejected === null
                                                    ? t(
                                                          'procurement.error.quantity',
                                                      )
                                                    : undefined)
                                            }
                                        />
                                        {rejected !== null && rejected > 0 && (
                                            <TextField
                                                label={t(
                                                    'procurement.reject_reason',
                                                )}
                                                value={entry.reject_reason}
                                                maxLength={500}
                                                onChange={(e) =>
                                                    setEntry(index, {
                                                        reject_reason:
                                                            e.target.value,
                                                    })
                                                }
                                                required
                                                error={
                                                    errors[
                                                        `lines.${index}.reject_reason`
                                                    ]
                                                }
                                            />
                                        )}
                                    </div>
                                    {valid &&
                                        (accepted > 0 || rejected > 0) && (
                                            <p
                                                className="text-body text-weak"
                                                aria-live="polite"
                                                data-test="receive-summary"
                                            >
                                                {t(
                                                    'procurement.receive_summary',
                                                    {
                                                        accepted: `${formatQty(accepted)} ${unit}`,
                                                        rejected: `${formatQty(rejected)} ${unit}`,
                                                        remaining: `${formatQty(Math.max(0, l.remaining_milli - accepted))} ${unit}`,
                                                    },
                                                )}
                                            </p>
                                        )}
                                    {over && (
                                        <Alert variant="warning">
                                            <Icon icon={TriangleAlert} />
                                            <AlertBody>
                                                {t('procurement.over_hint')}{' '}
                                                <Link
                                                    href={showUrl}
                                                    className="text-link underline"
                                                >
                                                    {t('procurement.over_link')}
                                                </Link>
                                            </AlertBody>
                                        </Alert>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                    {errors.lines && (
                        <p
                            className="text-caption text-danger-text"
                            role="alert"
                        >
                            {errors.lines}
                        </p>
                    )}
                </Card>

                <Card className="gap-3">
                    <div>
                        <Label htmlFor="proofs">
                            {t('procurement.proofs')}
                        </Label>
                        <p className="text-caption text-weak">
                            {t('procurement.proofs_hint', { n: MAX_PROOFS })}
                        </p>
                    </div>
                    <input
                        ref={fileInput}
                        id="proofs"
                        type="file"
                        multiple
                        disabled={form.data.proofs.length >= MAX_PROOFS}
                        onChange={(e) => addFiles(e.target.files)}
                        className="block w-full text-body file:me-3 file:min-h-11 file:cursor-pointer file:rounded-md file:border file:border-stroke-control file:bg-surface file:px-4 file:text-control"
                        data-test="proofs-input"
                    />
                    {form.data.proofs.length > 0 && (
                        <ul className="grid gap-1">
                            {form.data.proofs.map((file, i) => (
                                <li
                                    key={`${file.name}-${i}`}
                                    className="flex items-center justify-between gap-2 rounded-md bg-fill px-3 py-1"
                                >
                                    <span className="min-w-0 truncate">
                                        {file.name}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={t(
                                            'procurement.remove_file',
                                        )}
                                        onClick={() =>
                                            form.setData(
                                                'proofs',
                                                form.data.proofs.filter(
                                                    (_, n) => n !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <Icon icon={X} size="sm" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {proofError && (
                        <p
                            className="text-caption text-danger-text"
                            role="alert"
                        >
                            {proofError}
                        </p>
                    )}
                </Card>

                <div className="flex flex-wrap gap-2 max-md:*:flex-1">
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.processing}
                        data-test="submit-receipt"
                    >
                        {t('procurement.receive_submit')}
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={showUrl}>{t('catalog.cancel')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

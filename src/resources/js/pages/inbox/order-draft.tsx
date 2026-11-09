import { Head, router } from '@inertiajs/react';
import { Sparkles, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import OrderDraftController from '@/actions/App/Http/Controllers/Inbox/OrderDraftController';
import { LinePicker } from '@/components/orders/line-picker';
import type { DraftLine, OptionGroup } from '@/components/orders/types';
import { Amount } from '@/components/opsone/amount';
import {
    CheckboxField,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { BackLink } from '@/components/procurement/shared';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type LineType = 'custom_product' | 'custom_need' | 'ready_variant';

type Line = {
    key: string;
    type: LineType | null;
    product_id: number | null;
    variant_id: number | null;
    description: string;
    quantity: string | null;
    unit: string | null;
    width_cm: string | null;
    height_cm: string | null;
    depth_cm: string | null;
    color: string | null;
    options?: { key: string; value: string }[];
    name: string | null;
    option_groups: OptionGroup[];
    warnings: string[];
    confidence: 'high' | 'medium' | 'low';
    source: { id: number; text: string } | null;
};

type Branch = { id: number; name: string };

type Props = {
    conversation: {
        id: number;
        title: string;
        customer: { id: number; name: string; branch_id: number } | null;
    };
    draft: {
        id: number;
        need_summary: string;
        needs_measurement: boolean;
        created_at: string | null;
        lines: Line[];
    };
    branches: { custom: Branch[]; ready: Branch[] };
};

type PricedLine = {
    unit_price_minor: number | null;
    line_total_minor: number | null;
    reference_minor: number | null;
    note: string | null;
};

type Pricing = {
    money: { custom: boolean; ready: boolean };
    lines: Record<string, PricedLine>;
    ready_total_minor: number | null;
    reference_total_minor: number | null;
    reference_partial: boolean;
};

const payload = (line: Line) => ({
    key: line.key,
    type: line.type ?? undefined,
    product_id: line.product_id ?? undefined,
    variant_id: line.variant_id ?? undefined,
    description: line.description || undefined,
    quantity: line.quantity || undefined,
    unit: line.unit || undefined,
    width_cm: line.width_cm || undefined,
    height_cm: line.height_cm || undefined,
    depth_cm: line.depth_cm || undefined,
    color: line.color || undefined,
    options: line.options?.length ? line.options : undefined,
});

/**
 * AI-5 «مسودة طلب من المحادثة»: the staff member reviews what the assistant read from the conversation — each line
 * next to the message it came from — chooses catalog items the assistant could not match, corrects quantities and
 * sizes, and creates the order through the normal path. Every amount shown comes from the server and the catalog.
 */
export default function OrderDraftPage({
    conversation,
    draft,
    branches,
}: Props) {
    const t = useT();
    const [lines, setLines] = useState<Line[]>(draft.lines);
    const [summary, setSummary] = useState(draft.need_summary);
    const [needsMeasurement, setNeedsMeasurement] = useState(
        draft.needs_measurement,
    );
    const [pricing, setPricing] = useState<Pricing | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [failure, setFailure] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [choosing, setChoosing] = useState<string | null>(null);
    const [adding, setAdding] = useState<'custom' | 'ready' | null>(null);
    const idempotencyKey = useRef(crypto.randomUUID());
    const counter = useRef(0);

    const hasReady = lines.some((l) => l.type === 'ready_variant');
    const hasCustom = lines.some(
        (l) => l.type === 'custom_product' || l.type === 'custom_need',
    );
    const branchOptions = useMemo(() => {
        const ids = (list: Branch[]) => new Set(list.map((b) => b.id));
        const custom = ids(branches.custom);
        const ready = ids(branches.ready);
        const all = [...branches.custom, ...branches.ready].filter(
            (b, i, list) => list.findIndex((x) => x.id === b.id) === i,
        );

        return all.filter(
            (b) => (!hasReady || ready.has(b.id)) && (!hasCustom || custom.has(b.id)),
        );
    }, [branches, hasReady, hasCustom]);
    const [branchId, setBranchId] = useState<string>(() => {
        const preferred = conversation.customer?.branch_id;
        const all = [...branches.custom, ...branches.ready];

        return String(
            all.find((b) => b.id === preferred)?.id ?? all[0]?.id ?? '',
        );
    });

    const unresolved = lines.filter((l) => l.type === null).length;

    useEffect(() => {
        const resolved = lines.filter((l) => l.type !== null);
        if (resolved.length === 0 || branchId === '') {
            setPricing(null);

            return;
        }
        let cancelled = false;
        const timer = setTimeout(async () => {
            const result = await api<Pricing>(
                'POST',
                OrderDraftController.price.url([conversation.id, draft.id]),
                { branch_id: Number(branchId), lines: resolved.map(payload) },
            );
            if (!cancelled) {
                setPricing(result.ok ? result.data : null);
            }
        }, 300);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [lines, branchId, conversation.id, draft.id]);

    const update = (key: string, patch: Partial<Line>) =>
        setLines((current) =>
            current.map((l) => (l.key === key ? { ...l, ...patch } : l)),
        );

    const choose = (key: string, picked: DraftLine) => {
        update(key, {
            type: picked.type,
            product_id: picked.product_id ?? null,
            variant_id: picked.variant_id ?? null,
            name: picked.name,
            quantity: picked.quantity,
            unit: picked.unit || null,
            options: picked.options,
            description:
                picked.type === 'custom_need'
                    ? (picked.description ?? '')
                    : (lines.find((l) => l.key === key)?.description ?? ''),
            warnings: [],
        });
        setChoosing(null);
    };

    const add = (picked: DraftLine) => {
        counter.current += 1;
        setLines((current) => [
            ...current,
            {
                key: `n${counter.current}`,
                type: picked.type,
                product_id: picked.product_id ?? null,
                variant_id: picked.variant_id ?? null,
                description: picked.description ?? '',
                quantity: picked.quantity,
                unit: picked.unit || null,
                width_cm: null,
                height_cm: null,
                depth_cm: null,
                color: null,
                options: picked.options,
                name: picked.name,
                option_groups: [],
                warnings: [],
                confidence: 'high',
                source: null,
            },
        ]);
        setAdding(null);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (busy) {
            return;
        }
        setBusy(true);
        setErrors({});
        setFailure(null);

        const result = await api<{ order_ids: number[]; redirect: string }>(
            'POST',
            OrderDraftController.confirm.url([conversation.id, draft.id]),
            {
                idempotency_key: idempotencyKey.current,
                branch_id: branchId === '' ? undefined : Number(branchId),
                need_summary: summary,
                needs_measurement: needsMeasurement,
                lines: lines.map(payload),
            },
        );

        if (result.ok && result.data) {
            router.visit(result.data.redirect);

            return;
        }
        if (result.errors.idempotency_key) {
            idempotencyKey.current = crypto.randomUUID();
        }
        setErrors(result.errors);
        if (Object.keys(result.errors).length === 0) {
            setFailure(
                result.status === 403
                    ? t('inbox.order_draft.forbidden')
                    : result.status === 404
                      ? t('inbox.order_draft.closed')
                      : t('inbox.order_draft.failed'),
            );
        }
        setBusy(false);
    };

    const reject = () =>
        router.post(OrderDraftController.reject.url([conversation.id, draft.id]));

    const lineError = (index: number) =>
        Object.entries(errors)
            .filter(([k]) => k.startsWith(`lines.${index}.`))
            .map(([, v]) => v[0])[0];
    const otherErrors = Object.entries(errors)
        .filter(([k]) => !k.startsWith('lines.'))
        .map(([, v]) => v[0]);

    const money = (minor: number | null | undefined) =>
        minor == null ? null : <Amount value={minor / 100} />;

    return (
        <>
            <Head title={t('inbox.order_draft.title')} />
            <PageHeader
                back={
                    <BackLink
                        href={InboxController.show.url(conversation.id)}
                        label={conversation.title}
                    />
                }
                title={t('inbox.order_draft.title')}
                description={t('inbox.order_draft.description')}
            />

            <form
                onSubmit={submit}
                className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]"
                noValidate
                data-test="order-draft-form"
            >
                <div className="grid gap-4">
                    <Alert variant="info">
                        <Sparkles />
                        <AlertBody>{t('inbox.order_draft.review_hint')}</AlertBody>
                    </Alert>
                    {(failure || otherErrors.length > 0) && (
                        <Alert variant="danger" role="alert" data-test="form-errors">
                            <AlertBody>{failure ?? otherErrors.join(' ')}</AlertBody>
                        </Alert>
                    )}
                    {conversation.customer === null && (
                        <Alert variant="warning" role="alert">
                            <AlertBody>{t('inbox.order_draft.no_customer')}</AlertBody>
                        </Alert>
                    )}

                    <ol className="grid gap-3" data-test="draft-lines">
                        {lines.map((line, index) => {
                            const priced = pricing?.lines[line.key];

                            return (
                                <li key={line.key}>
                                    <Card className="grid gap-3" data-test="draft-line">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-semibold text-strong">
                                                    {line.type === 'custom_need'
                                                        ? t('inbox.order_draft.need')
                                                        : (line.name ??
                                                          t('inbox.order_draft.needs_choice'))}
                                                </p>
                                                {line.description && (
                                                    <p className="text-caption text-weak">
                                                        {line.description}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant={
                                                        line.confidence === 'high'
                                                            ? 'success'
                                                            : line.confidence === 'medium'
                                                              ? 'info'
                                                              : 'warning'
                                                    }
                                                >
                                                    {t(
                                                        `inbox.order_draft.confidence.${line.confidence}` as TranslationKey,
                                                    )}
                                                </Badge>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setLines((c) =>
                                                            c.filter((l) => l.key !== line.key),
                                                        )
                                                    }
                                                    aria-label={t('inbox.order_draft.remove')}
                                                >
                                                    <Icon icon={Trash2} />
                                                </Button>
                                            </div>
                                        </div>

                                        {line.source && (
                                            <blockquote
                                                className="border-s-2 border-stroke-weak ps-3 text-caption text-weak"
                                                data-test="draft-line-source"
                                            >
                                                {t('inbox.order_draft.source')}: {line.source.text}
                                            </blockquote>
                                        )}

                                        {line.warnings.length > 0 && (
                                            <ul className="flex flex-wrap gap-2">
                                                {line.warnings.map((w) => (
                                                    <li key={w}>
                                                        <Badge variant="warning">
                                                            {t(
                                                                `inbox.order_draft.warning.${w}` as TranslationKey,
                                                            )}
                                                        </Badge>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}

                                        {line.type === null ? (
                                            <div className="grid gap-2">
                                                <div className="flex flex-wrap gap-2">
                                                    {branches.custom.length > 0 && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                setChoosing(`${line.key}|custom`)
                                                            }
                                                        >
                                                            {t('inbox.order_draft.choose_custom')}
                                                        </Button>
                                                    )}
                                                    {branches.ready.length > 0 && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                setChoosing(`${line.key}|ready`)
                                                            }
                                                        >
                                                            {t('inbox.order_draft.choose_ready')}
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        ) : null}
                                        {choosing?.startsWith(`${line.key}|`) && (
                                            <LinePicker
                                                kind={choosing.endsWith('|ready') ? 'ready' : 'custom'}
                                                branchId={branchId === '' ? null : Number(branchId)}
                                                onAdd={(picked) => choose(line.key, picked)}
                                            />
                                        )}

                                        {line.type !== null && (
                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                <TextField
                                                    label={t('inbox.order_draft.quantity')}
                                                    type="number"
                                                    inputMode="decimal"
                                                    dir="ltr"
                                                    min={line.type === 'ready_variant' ? 1 : 0.001}
                                                    step={line.type === 'ready_variant' ? 1 : 'any'}
                                                    value={line.quantity ?? ''}
                                                    onChange={(e) =>
                                                        update(line.key, { quantity: e.target.value })
                                                    }
                                                />
                                                {line.type === 'custom_product' &&
                                                    (['width_cm', 'height_cm', 'depth_cm'] as const).map(
                                                        (dim) => (
                                                            <TextField
                                                                key={dim}
                                                                label={t(
                                                                    `inbox.order_draft.${dim}` as TranslationKey,
                                                                )}
                                                                type="number"
                                                                inputMode="decimal"
                                                                dir="ltr"
                                                                min={1}
                                                                max={3000}
                                                                step={0.1}
                                                                value={line[dim] ?? ''}
                                                                onChange={(e) =>
                                                                    update(line.key, {
                                                                        [dim]: e.target.value,
                                                                    })
                                                                }
                                                                optional
                                                            />
                                                        ),
                                                    )}
                                            </div>
                                        )}
                                        {line.type !== null && line.type !== 'ready_variant' && (
                                            <TextField
                                                label={t('inbox.order_draft.line_description')}
                                                value={line.description}
                                                maxLength={2000}
                                                onChange={(e) =>
                                                    update(line.key, { description: e.target.value })
                                                }
                                                optional={line.type !== 'custom_need'}
                                            />
                                        )}

                                        {priced && (
                                            <p
                                                className="flex flex-wrap items-center gap-2 text-caption text-weak"
                                                data-test="draft-line-price"
                                            >
                                                {line.type === 'ready_variant' &&
                                                    priced.line_total_minor != null && (
                                                        <>
                                                            {t('inbox.order_draft.line_total')}:{' '}
                                                            {money(priced.line_total_minor)}
                                                        </>
                                                    )}
                                                {line.type === 'custom_product' &&
                                                    priced.reference_minor != null && (
                                                        <>
                                                            {t('inbox.order_draft.reference')}:{' '}
                                                            {money(priced.reference_minor)}
                                                        </>
                                                    )}
                                                {priced.note && (
                                                    <span>
                                                        {t(
                                                            `inbox.order_draft.note.${priced.note}` as TranslationKey,
                                                        )}
                                                    </span>
                                                )}
                                            </p>
                                        )}
                                        {lineError(index) && (
                                            <p className="text-caption text-danger-text" role="alert">
                                                {lineError(index)}
                                            </p>
                                        )}
                                    </Card>
                                </li>
                            );
                        })}
                    </ol>

                    <Card className="grid gap-3">
                        <div className="flex flex-wrap gap-2">
                            {branches.custom.length > 0 && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setAdding('custom')}
                                >
                                    {t('inbox.order_draft.add_custom')}
                                </Button>
                            )}
                            {branches.ready.length > 0 && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setAdding('ready')}
                                >
                                    {t('inbox.order_draft.add_ready')}
                                </Button>
                            )}
                        </div>
                        {adding && (
                            <LinePicker
                                kind={adding}
                                branchId={branchId === '' ? null : Number(branchId)}
                                onAdd={add}
                            />
                        )}
                    </Card>
                </div>

                <Card className="grid gap-4 lg:sticky lg:top-4">
                    <p className="text-caption text-weak">
                        {t('inbox.order_draft.customer')}:{' '}
                        <span className="font-semibold text-strong">
                            {conversation.customer?.name ?? '—'}
                        </span>
                    </p>
                    <SelectField
                        label={t('inbox.order_draft.branch')}
                        value={branchId}
                        onChange={(e) => setBranchId(e.target.value)}
                        options={branchOptions.map((b) => ({
                            value: String(b.id),
                            label: b.name,
                        }))}
                        error={errors.branch_id?.[0]}
                    />
                    {hasCustom && (
                        <>
                            <TextareaField
                                label={t('inbox.order_draft.need_summary')}
                                value={summary}
                                maxLength={2000}
                                onChange={(e) => setSummary(e.target.value)}
                                error={errors.need_summary?.[0]}
                            />
                            <CheckboxField
                                label={t('inbox.order_draft.needs_measurement')}
                                checked={needsMeasurement}
                                onChange={(e) => setNeedsMeasurement(e.target.checked)}
                            />
                        </>
                    )}

                    {pricing && (pricing.money.ready || pricing.money.custom) && (
                        <dl className="grid gap-2 text-control" data-test="draft-totals">
                            {hasReady && pricing.ready_total_minor != null && (
                                <div className="flex justify-between gap-2">
                                    <dt>{t('inbox.order_draft.ready_total')}</dt>
                                    <dd>{money(pricing.ready_total_minor)}</dd>
                                </div>
                            )}
                            {hasCustom && pricing.reference_total_minor != null && (
                                <div className="flex justify-between gap-2">
                                    <dt>{t('inbox.order_draft.reference_total')}</dt>
                                    <dd>{money(pricing.reference_total_minor)}</dd>
                                </div>
                            )}
                            <p className="text-caption text-weak">
                                {t('inbox.order_draft.prices_hint')}
                            </p>
                        </dl>
                    )}

                    {unresolved > 0 && (
                        <p className="text-caption text-warning-text" role="status">
                            {t('inbox.order_draft.unresolved', { n: unresolved })}
                        </p>
                    )}
                    <Button
                        type="submit"
                        disabled={
                            busy ||
                            unresolved > 0 ||
                            lines.length === 0 ||
                            conversation.customer === null ||
                            branchId === ''
                        }
                        data-test="order-draft-confirm"
                    >
                        {t('inbox.order_draft.confirm')}
                    </Button>
                    <Button type="button" variant="ghost" onClick={reject}>
                        {t('inbox.order_draft.reject')}
                    </Button>
                </Card>
            </form>
        </>
    );
}

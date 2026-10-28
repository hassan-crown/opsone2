import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { CustomerPicker } from '@/components/orders/customer-picker';
import { DraftLines } from '@/components/orders/lines';
import { LinePicker } from '@/components/orders/line-picker';
import { toPayload } from '@/components/orders/types';
import type { CustomerHit, DraftLine, Option } from '@/components/orders/types';
import {
    CheckboxField,
    RadioGroup,
    SelectField,
    TextareaField,
} from '@/components/opsone/field';
import { notify } from '@/components/opsone/toast';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    options: {
        branches: { custom: Option[]; ready: Option[] };
        members: Option[];
        invoice_types: string[];
    };
    preselectedCustomer: CustomerHit | null;
};

type Mode = 'custom' | 'ready' | 'both';

/** CreateOrder / CreateOrderMobile: custom, ready, or both (two orders sharing a purchase reference). */
export default function CreateOrder({ options, preselectedCustomer }: Props) {
    const t = useT();
    const { auth } = usePage().props;
    const customBranches = options.branches.custom;
    const readyBranches = options.branches.ready;
    const bothBranches = customBranches.filter((b) =>
        readyBranches.some((r) => r.id === b.id),
    );

    const modes: Mode[] = [
        ...(customBranches.length ? (['custom'] as const) : []),
        ...(readyBranches.length ? (['ready'] as const) : []),
        ...(bothBranches.length ? (['both'] as const) : []),
    ];

    // One key per form instance: a retry or double click replays the same request instead of opening twice.
    const idempotencyKey = useRef(crypto.randomUUID());
    const submitting = useRef(false);

    const [mode, setMode] = useState<Mode>(modes[0] ?? 'custom');
    const [customer, setCustomer] = useState<CustomerHit | null>(
        preselectedCustomer,
    );
    const [branchId, setBranchId] = useState('');
    const [assigneeId, setAssigneeId] = useState('');
    const [needSummary, setNeedSummary] = useState('');
    const [needsMeasurement, setNeedsMeasurement] = useState(false);
    const [needsDesign, setNeedsDesign] = useState(false);
    const [customInvoice, setCustomInvoice] = useState('');
    const [readyInvoice, setReadyInvoice] = useState('');
    const [customLines, setCustomLines] = useState<DraftLine[]>([]);
    const [readyLines, setReadyLines] = useState<DraftLine[]>([]);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [failure, setFailure] = useState<string | null>(null);

    const branches =
        mode === 'both'
            ? bothBranches
            : mode === 'ready'
              ? readyBranches
              : customBranches;
    const effectiveBranch = branches.some((b) => String(b.id) === branchId)
        ? branchId
        : String(branches[0]?.id ?? '');
    const branchNumber =
        effectiveBranch === '' ? null : Number(effectiveBranch);
    const hasCustom = mode !== 'ready';
    const hasReady = mode !== 'custom';

    // Default the branch to the customer's own when the user may open orders there.
    const chooseCustomer = (next: CustomerHit | null) => {
        setCustomer(next);
        if (next && branches.some((b) => b.id === next.branch_id)) {
            setBranchId(String(next.branch_id));
        }
    };

    const first = (name: string): string | undefined => errors[name]?.[0];
    const errorCount = Object.keys(errors).length;
    const invoiceOptions = useMemo(
        () => [
            { value: '', label: t('orders.invoice.none') },
            ...options.invoice_types.map((v) => ({
                value: v,
                label: t(`orders.invoice.${v}` as TranslationKey),
            })),
        ],
        [options.invoice_types, t],
    );

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (submitting.current) {
            return;
        }
        submitting.current = true;
        setBusy(true);
        setErrors({});
        setFailure(null);

        const body = {
            idempotency_key: idempotencyKey.current,
            customer_id: customer?.id,
            branch_id: branchNumber,
            assignee_id: assigneeId === '' ? undefined : Number(assigneeId),
            custom: hasCustom
                ? {
                      need_summary: needSummary,
                      needs_measurement: needsMeasurement,
                      needs_design: needsDesign,
                      invoice_type: customInvoice || null,
                      lines: customLines.map(toPayload),
                  }
                : undefined,
            ready: hasReady
                ? {
                      invoice_type: readyInvoice || null,
                      lines: readyLines.map(toPayload),
                  }
                : undefined,
        };

        const result = await api<{
            orders: { id: number; number: string }[];
        }>('POST', OrderController.store.url(), body);

        if (result.ok && result.data) {
            notify('success', {
                title: t('orders.create.created', {
                    numbers: result.data.orders
                        .map((o) => o.number)
                        .join(' · '),
                }),
            });
            // Stay locked while navigating so a second click cannot open another order.
            router.visit(OrderController.show.url(result.data.orders[0].id));

            return;
        }

        if (result.errors.idempotency_key) {
            idempotencyKey.current = crypto.randomUUID();
        }
        setErrors(result.errors);
        if (Object.keys(result.errors).length === 0) {
            setFailure(
                result.status === 429
                    ? t('orders.create.too_many')
                    : result.status === 403
                      ? t('orders.create.forbidden')
                      : t('orders.create.failed'),
            );
        }
        submitting.current = false;
        setBusy(false);
    };

    const partErrors = (prefix: string) =>
        Object.entries(errors)
            .filter(([k]) => k.startsWith(prefix))
            .map(([, v]) => v[0]);

    return (
        <>
            <Head title={t('orders.create.title')} />
            <PageHeader
                title={t('orders.create.title')}
                description={t('orders.create.description')}
            />

            <form
                onSubmit={submit}
                className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]"
                aria-label={t('orders.create.title')}
                noValidate
                data-test="create-order-form"
            >
                <div className="grid gap-4">
                    {(errorCount > 0 || failure) && (
                        <Alert
                            variant="danger"
                            role="alert"
                            data-test="form-errors"
                        >
                            <AlertBody>
                                {failure ??
                                    t('orders.create.errors', {
                                        n: errorCount,
                                    })}
                            </AlertBody>
                        </Alert>
                    )}

                    <Card>
                        <h2 className="text-heading mb-4 text-strong">
                            {t('orders.create.kind')}
                        </h2>
                        <RadioGroup
                            legend={t('orders.create.kind')}
                            name="mode"
                            inline
                            value={mode}
                            onChange={(v) => setMode(v as Mode)}
                            options={modes.map((m) => ({
                                value: m,
                                label: t(
                                    `orders.create.mode.${m}` as TranslationKey,
                                ),
                            }))}
                        />
                        {mode === 'both' && (
                            <p className="mt-3 text-caption text-weak">
                                {t('orders.create.both_hint')}
                            </p>
                        )}
                    </Card>

                    <Card>
                        <h2 className="text-heading mb-4 text-strong">
                            {t('orders.create.customer')}
                        </h2>
                        <CustomerPicker
                            value={customer}
                            onChange={chooseCustomer}
                            error={first('customer_id')}
                        />
                    </Card>

                    <Card>
                        <h2 className="text-heading mb-4 text-strong">
                            {t('orders.create.order')}
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SelectField
                                label={t('orders.field.branch')}
                                value={effectiveBranch}
                                onChange={(e) => setBranchId(e.target.value)}
                                options={branches.map((b) => ({
                                    value: String(b.id),
                                    label: b.name,
                                }))}
                                error={first('branch_id')}
                                required
                            />
                            <SelectField
                                label={t('orders.field.assignee')}
                                hint={t('orders.create.assignee_hint')}
                                value={assigneeId}
                                onChange={(e) => setAssigneeId(e.target.value)}
                                options={[
                                    {
                                        value: '',
                                        label: t('orders.create.assignee_me'),
                                    },
                                    ...options.members
                                        .filter((m) => m.id !== auth.user?.id)
                                        .map((m) => ({
                                            value: String(m.id),
                                            label: m.name,
                                        })),
                                ]}
                                error={
                                    first('assignee_id') ??
                                    first('custom.assignee_id')
                                }
                            />
                        </div>
                    </Card>

                    {hasCustom && (
                        <Card data-test="custom-section">
                            <h2 className="text-heading mb-1 text-strong">
                                {t('orders.create.custom_part')}
                            </h2>
                            <p className="mb-4 text-caption text-weak">
                                {t('orders.create.custom_hint')}
                            </p>
                            <div className="grid gap-4">
                                <TextareaField
                                    label={t('orders.field.need_summary')}
                                    hint={t('orders.create.need_hint')}
                                    value={needSummary}
                                    onChange={(e) =>
                                        setNeedSummary(e.target.value)
                                    }
                                    maxLength={2000}
                                    error={first('custom.need_summary')}
                                    required
                                    data-test="need-summary"
                                />
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <CheckboxField
                                        label={t(
                                            'orders.field.needs_measurement',
                                        )}
                                        checked={needsMeasurement}
                                        onChange={(e) =>
                                            setNeedsMeasurement(
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <CheckboxField
                                        label={t('orders.field.needs_design')}
                                        checked={needsDesign}
                                        onChange={(e) =>
                                            setNeedsDesign(e.target.checked)
                                        }
                                    />
                                </div>
                                <SelectField
                                    label={t('orders.field.invoice_type')}
                                    value={customInvoice}
                                    onChange={(e) =>
                                        setCustomInvoice(e.target.value)
                                    }
                                    options={invoiceOptions}
                                    error={first('custom.invoice_type')}
                                    optional
                                />
                                <div className="grid gap-3">
                                    <h3 className="font-semibold text-strong">
                                        {t('orders.create.lines_optional')}
                                    </h3>
                                    <DraftLines
                                        lines={customLines}
                                        onRemove={(k) =>
                                            setCustomLines(
                                                customLines.filter(
                                                    (l) => l.key !== k,
                                                ),
                                            )
                                        }
                                    />
                                    {partErrors('custom.lines').map((m, i) => (
                                        <p
                                            key={i}
                                            role="alert"
                                            className="text-caption text-danger-text"
                                        >
                                            {m}
                                        </p>
                                    ))}
                                    <LinePicker
                                        kind="custom"
                                        branchId={branchNumber}
                                        onAdd={(l) =>
                                            setCustomLines([...customLines, l])
                                        }
                                    />
                                </div>
                            </div>
                        </Card>
                    )}

                    {hasReady && (
                        <Card data-test="ready-section">
                            <h2 className="text-heading mb-1 text-strong">
                                {t('orders.create.ready_part')}
                            </h2>
                            <p className="mb-4 text-caption text-weak">
                                {t('orders.create.ready_hint')}
                            </p>
                            <div className="grid gap-4">
                                <SelectField
                                    label={t('orders.field.invoice_type')}
                                    value={readyInvoice}
                                    onChange={(e) =>
                                        setReadyInvoice(e.target.value)
                                    }
                                    options={invoiceOptions}
                                    error={first('ready.invoice_type')}
                                    optional
                                />
                                <div className="grid gap-3">
                                    <h3 className="font-semibold text-strong">
                                        {t('orders.create.lines_required')}
                                    </h3>
                                    <DraftLines
                                        lines={readyLines}
                                        onRemove={(k) =>
                                            setReadyLines(
                                                readyLines.filter(
                                                    (l) => l.key !== k,
                                                ),
                                            )
                                        }
                                    />
                                    {partErrors('ready.lines').map((m, i) => (
                                        <p
                                            key={i}
                                            role="alert"
                                            className="text-caption text-danger-text"
                                        >
                                            {m}
                                        </p>
                                    ))}
                                    {first('ready') && (
                                        <p
                                            role="alert"
                                            className="text-caption text-danger-text"
                                        >
                                            {first('ready')}
                                        </p>
                                    )}
                                    <LinePicker
                                        kind="ready"
                                        branchId={branchNumber}
                                        onAdd={(l) =>
                                            setReadyLines([...readyLines, l])
                                        }
                                    />
                                </div>
                            </div>
                        </Card>
                    )}
                </div>

                <aside className="grid content-start gap-4 lg:sticky lg:top-4">
                    <Card>
                        <h2 className="text-heading mb-3 text-strong">
                            {t('orders.create.summary')}
                        </h2>
                        <dl className="grid gap-2 text-body">
                            <div className="flex justify-between gap-2">
                                <dt className="text-weak">
                                    {t('orders.create.customer')}
                                </dt>
                                <dd className="font-semibold">
                                    {customer?.name ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-2">
                                <dt className="text-weak">
                                    {t('orders.field.branch')}
                                </dt>
                                <dd className="font-semibold">
                                    {branches.find(
                                        (b) => String(b.id) === effectiveBranch,
                                    )?.name ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-2">
                                <dt className="text-weak">
                                    {t('orders.create.kind')}
                                </dt>
                                <dd className="font-semibold">
                                    {t(
                                        `orders.create.mode.${mode}` as TranslationKey,
                                    )}
                                </dd>
                            </div>
                        </dl>
                        {first('idempotency_key') && (
                            <p
                                role="alert"
                                className="mt-3 text-caption text-danger-text"
                            >
                                {first('idempotency_key')}
                            </p>
                        )}
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                loading={busy}
                                data-test="create-order-submit"
                            >
                                {t('orders.create.submit')}
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href={OrderController.index()}>
                                    {t('orders.create.cancel')}
                                </Link>
                            </Button>
                        </div>
                    </Card>
                </aside>
            </form>
        </>
    );
}

CreateOrder.layout = {
    breadcrumbs: [
        { title: 'orders.nav', href: OrderController.index() },
        { title: 'orders.create.title', href: OrderController.create() },
    ],
};

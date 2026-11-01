import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import EInvoicingController from '@/actions/App/Http/Controllers/EInvoicing/EInvoicingController';
import {
    CheckboxField,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatAmount, formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { edit } from '@/routes/einvoicing';
import { show as orderShow } from '@/routes/orders';
import { home } from '@/routes/settings';

type Settings = {
    seller_name_ar: string | null;
    seller_name_en: string | null;
    vat_number: string | null;
    commercial_registration: string | null;
    street: string | null;
    building_number: string | null;
    additional_number: string | null;
    district: string | null;
    city: string | null;
    postal_code: string | null;
    branch_name: string | null;
    industry: string | null;
    enabled: boolean;
    environment: string;
    enabled_at: string | null;
    lock_version: number | null;
    profile_complete: boolean;
};

type Device = {
    id: number;
    status: 'draft' | 'compliance' | 'active' | 'retired';
    environment: string;
    common_name: string;
    onboarded_at: string | null;
    certificate_expires_at: string | null;
    compliance_checks: Record<string, string>;
    last_error: string | null;
};

type Status =
    | 'not_required'
    | 'pending'
    | 'reported'
    | 'cleared'
    | 'rejected'
    | 'failed';

type Row = {
    id: number;
    number: string;
    kind: 'invoice' | 'credit_note' | 'debit_note';
    type: 'standard' | 'simplified';
    order: { id: number; number: string };
    customer: string;
    original: string | null;
    total_minor: number;
    vat_minor: number;
    issued_at: string;
    status: Status;
    warnings: boolean;
    attempts: number;
    messages: { code: string; message: string }[];
    error: string | null;
    can_retry: boolean;
    creditable_minor: number | null;
};

type Props = {
    settings: Settings;
    environments: string[];
    device: Device | null;
    counts: Partial<Record<Status, number>>;
    filter: Status | null;
    invoices: Row[];
    missing: { id: number; number: string; customer: string }[];
};

const STATUSES: Status[] = [
    'pending',
    'reported',
    'cleared',
    'rejected',
    'failed',
    'not_required',
];

const STATUS_VARIANT: Record<
    Status,
    'success' | 'info' | 'warning' | 'danger' | 'neutral'
> = {
    not_required: 'neutral',
    pending: 'info',
    reported: 'success',
    cleared: 'success',
    rejected: 'danger',
    failed: 'warning',
};

const PROFILE_FIELDS: ReadonlyArray<
    readonly [keyof Settings, 'ltr' | undefined, boolean]
> = [
    ['seller_name_ar', undefined, true],
    ['seller_name_en', 'ltr', false],
    ['vat_number', 'ltr', true],
    ['commercial_registration', 'ltr', true],
    ['street', undefined, true],
    ['building_number', 'ltr', true],
    ['additional_number', 'ltr', false],
    ['district', undefined, true],
    ['city', undefined, true],
    ['postal_code', 'ltr', true],
    ['branch_name', undefined, false],
    ['industry', undefined, false],
];

function NoteForm({ row, onDone }: { row: Row; onDone: () => void }) {
    const t = useT();

    return (
        <Form
            {...EInvoicingController.note.form(row.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="mt-3 grid gap-3 rounded-md border border-stroke-weak p-3 sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <SelectField
                        label={t('einvoicing.note.kind')}
                        name="kind"
                        defaultValue="credit_note"
                        required
                        options={[
                            {
                                value: 'credit_note',
                                label: t('einvoicing.kind.credit_note'),
                            },
                            {
                                value: 'debit_note',
                                label: t('einvoicing.kind.debit_note'),
                            },
                        ]}
                        error={errors.kind}
                    />
                    <TextField
                        label={t('einvoicing.note.amount')}
                        hint={
                            row.creditable_minor !== null
                                ? t('einvoicing.note.creditable', {
                                      amount: (
                                          row.creditable_minor / 100
                                      ).toFixed(2),
                                  })
                                : undefined
                        }
                        name="amount"
                        dir="ltr"
                        inputMode="decimal"
                        required
                        error={errors.amount}
                    />
                    <TextareaField
                        label={t('einvoicing.note.reason')}
                        name="reason"
                        required
                        error={errors.reason}
                        className="sm:col-span-2"
                    />
                    <div className="flex gap-2 sm:col-span-2">
                        <Button
                            type="submit"
                            loading={processing}
                            data-test="issue-note"
                        >
                            {t('einvoicing.note.issue')}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            {t('einvoicing.cancel')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function SettingsEInvoicing({
    settings,
    environments,
    device,
    counts,
    filter,
    invoices,
    missing,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [busy, setBusy] = useState<string | null>(null);
    const [noteFor, setNoteFor] = useState<number | null>(null);

    const post = (key: string, url: string) => {
        setBusy(key);
        router.post(
            url,
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <>
            <Head title={t('einvoicing.title')} />

            <PageHeader
                title={t('einvoicing.title')}
                description={t('einvoicing.description')}
            />

            <div className="max-w-4xl space-y-10">
                <section className="space-y-4" data-test="zatca-profile">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-subtitle text-strong">
                                {t('einvoicing.profile.title')}
                            </h2>
                            <p className="text-body text-weak">
                                {t('einvoicing.profile.description')}
                            </p>
                        </div>
                        <Badge
                            variant={settings.enabled ? 'success' : 'neutral'}
                        >
                            {settings.enabled
                                ? t('einvoicing.status.enabled')
                                : t('einvoicing.status.disabled')}
                        </Badge>
                    </div>

                    <Form
                        {...EInvoicingController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={settings.lock_version ?? ''}
                                />
                                <div className="grid gap-6 sm:grid-cols-2">
                                    {PROFILE_FIELDS.map(
                                        ([key, dir, required]) => (
                                            <TextField
                                                key={key}
                                                label={t(
                                                    `einvoicing.profile.${key}` as TranslationKey,
                                                )}
                                                name={key}
                                                dir={dir}
                                                defaultValue={
                                                    (settings[key] as
                                                        | string
                                                        | null) ?? ''
                                                }
                                                optional={!required}
                                                error={errors[key]}
                                            />
                                        ),
                                    )}
                                    <SelectField
                                        label={t('einvoicing.environment')}
                                        hint={t('einvoicing.environment_hint')}
                                        name="environment"
                                        defaultValue={settings.environment}
                                        required
                                        options={environments.map((env) => ({
                                            value: env,
                                            label: t(
                                                `einvoicing.env.${env}` as TranslationKey,
                                            ),
                                        }))}
                                        error={errors.environment}
                                    />
                                </div>
                                <input type="hidden" name="enabled" value="0" />
                                <CheckboxField
                                    label={t('einvoicing.enable')}
                                    hint={t('einvoicing.enable_hint')}
                                    name="enabled"
                                    value="1"
                                    defaultChecked={settings.enabled}
                                    error={errors.enabled}
                                    data-test="zatca-enabled"
                                />
                                <Button
                                    type="submit"
                                    loading={processing}
                                    data-test="save-zatca"
                                >
                                    {processing && <Spinner />}
                                    {t('einvoicing.save')}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section
                    className="space-y-4 border-t border-stroke-weak pt-8"
                    data-test="zatca-onboarding"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-subtitle text-strong">
                                {t('einvoicing.onboarding.title')}
                            </h2>
                            <p className="text-body text-weak">
                                {t('einvoicing.onboarding.description')}
                            </p>
                        </div>
                        <Badge
                            variant={
                                device?.status === 'active'
                                    ? 'success'
                                    : device
                                      ? 'warning'
                                      : 'neutral'
                            }
                        >
                            {t(
                                `einvoicing.device.${device?.status ?? 'none'}` as TranslationKey,
                            )}
                        </Badge>
                    </div>

                    {device && (
                        <Card className="space-y-2 text-body">
                            <p>
                                {t('einvoicing.device.name')}:{' '}
                                <span dir="ltr">{device.common_name}</span> —{' '}
                                {t(
                                    `einvoicing.env.${device.environment}` as TranslationKey,
                                )}
                            </p>
                            {device.onboarded_at && (
                                <p>
                                    {t('einvoicing.device.onboarded_at')}:{' '}
                                    {formatDateTime(
                                        device.onboarded_at,
                                        locale,
                                    )}
                                </p>
                            )}
                            {device.certificate_expires_at && (
                                <p>
                                    {t('einvoicing.device.expires_at')}:{' '}
                                    {formatDateTime(
                                        device.certificate_expires_at,
                                        locale,
                                    )}
                                </p>
                            )}
                            {Object.keys(device.compliance_checks).length >
                                0 && (
                                <ul className="grid gap-1 sm:grid-cols-2">
                                    {Object.entries(
                                        device.compliance_checks,
                                    ).map(([name, result]) => (
                                        <li
                                            key={name}
                                            className="flex items-center gap-2"
                                        >
                                            <Badge
                                                variant={
                                                    result === 'failed'
                                                        ? 'danger'
                                                        : 'success'
                                                }
                                            >
                                                {t(
                                                    `einvoicing.check.${result}` as TranslationKey,
                                                )}
                                            </Badge>
                                            {t(
                                                `einvoicing.sample.${name}` as TranslationKey,
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {device.last_error && (
                                <p className="text-danger" dir="ltr">
                                    {device.last_error}
                                </p>
                            )}
                            {device.status === 'compliance' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    loading={busy === 'resume'}
                                    onClick={() =>
                                        post(
                                            'resume',
                                            EInvoicingController.resume.url(),
                                        )
                                    }
                                >
                                    {t('einvoicing.onboarding.resume')}
                                </Button>
                            )}
                        </Card>
                    )}

                    <Form
                        {...EInvoicingController.onboard.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="flex flex-wrap items-end gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <TextField
                                    label={t('einvoicing.onboarding.otp')}
                                    hint={t('einvoicing.onboarding.otp_hint')}
                                    name="otp"
                                    dir="ltr"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    required
                                    error={errors.otp}
                                />
                                <Button
                                    type="submit"
                                    disabled={!settings.profile_complete}
                                    loading={processing}
                                    data-test="zatca-onboard"
                                >
                                    {device?.status === 'active'
                                        ? t('einvoicing.onboarding.renew')
                                        : t('einvoicing.onboarding.start')}
                                </Button>
                            </>
                        )}
                    </Form>
                    {!settings.profile_complete && (
                        <p className="text-caption text-weak">
                            {t('einvoicing.blocked.profile_incomplete')}
                        </p>
                    )}
                </section>

                {missing.length > 0 && (
                    <section
                        className="space-y-3 border-t border-stroke-weak pt-8"
                        data-test="zatca-missing"
                    >
                        <h2 className="text-subtitle text-strong">
                            {t('einvoicing.missing.title')}
                        </h2>
                        <p className="text-body text-weak">
                            {t('einvoicing.missing.description')}
                        </p>
                        <ul className="divide-y divide-stroke-weak">
                            {missing.map((order) => (
                                <li
                                    key={order.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-2"
                                >
                                    <span>
                                        <Link
                                            href={orderShow(order.id)}
                                            dir="ltr"
                                            className="text-link"
                                        >
                                            {order.number}
                                        </Link>{' '}
                                        — {order.customer}
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        loading={busy === `issue-${order.id}`}
                                        onClick={() =>
                                            post(
                                                `issue-${order.id}`,
                                                EInvoicingController.issue.url(
                                                    order.id,
                                                ),
                                            )
                                        }
                                    >
                                        {t('einvoicing.missing.issue')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section
                    className="space-y-4 border-t border-stroke-weak pt-8"
                    data-test="zatca-documents"
                >
                    <h2 className="text-subtitle text-strong">
                        {t('einvoicing.documents.title')}
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            asChild
                            size="sm"
                            variant={filter === null ? 'default' : 'outline'}
                        >
                            <Link href={edit()} preserveScroll>
                                {t('einvoicing.documents.all')}
                            </Link>
                        </Button>
                        {STATUSES.map((status) => (
                            <Button
                                key={status}
                                asChild
                                size="sm"
                                variant={
                                    filter === status ? 'default' : 'outline'
                                }
                            >
                                <Link
                                    href={edit({ query: { status } })}
                                    preserveScroll
                                >
                                    {t(
                                        `einvoicing.submission.${status}` as TranslationKey,
                                    )}{' '}
                                    ({counts[status] ?? 0})
                                </Link>
                            </Button>
                        ))}
                    </div>

                    {invoices.length === 0 ? (
                        <p className="text-body text-weak">
                            {t('einvoicing.documents.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-stroke-weak">
                            {invoices.map((row) => (
                                <li
                                    key={row.id}
                                    className="py-3"
                                    data-test="einvoice-row"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="space-y-1">
                                            <p className="font-semibold text-strong">
                                                <span dir="ltr">
                                                    {row.number}
                                                </span>{' '}
                                                ·{' '}
                                                {t(
                                                    `einvoicing.kind.${row.kind}` as TranslationKey,
                                                )}{' '}
                                                ·{' '}
                                                {t(
                                                    `einvoicing.type.${row.type}` as TranslationKey,
                                                )}
                                            </p>
                                            <p className="text-caption text-weak">
                                                <Link
                                                    href={orderShow(
                                                        row.order.id,
                                                    )}
                                                    dir="ltr"
                                                    className="text-link"
                                                >
                                                    {row.order.number}
                                                </Link>{' '}
                                                — {row.customer}
                                                {row.original && (
                                                    <>
                                                        {' '}
                                                        —{' '}
                                                        {t(
                                                            'einvoicing.documents.original',
                                                        )}{' '}
                                                        <span dir="ltr">
                                                            {row.original}
                                                        </span>
                                                    </>
                                                )}
                                            </p>
                                            <p className="text-caption text-weak">
                                                {formatDateTime(
                                                    row.issued_at,
                                                    locale,
                                                )}{' '}
                                                ·{' '}
                                                {formatAmount(
                                                    row.total_minor / 100,
                                                    locale,
                                                )}{' '}
                                                ({t('einvoicing.documents.vat')}{' '}
                                                {formatAmount(
                                                    row.vat_minor / 100,
                                                    locale,
                                                )}
                                                )
                                            </p>
                                        </div>
                                        <div className="flex flex-col items-end gap-1">
                                            <Badge
                                                variant={
                                                    STATUS_VARIANT[row.status]
                                                }
                                            >
                                                {t(
                                                    `einvoicing.submission.${row.status}` as TranslationKey,
                                                )}
                                                {row.warnings &&
                                                    ` · ${t('einvoicing.documents.warnings')}`}
                                            </Badge>
                                            {row.attempts > 0 && (
                                                <span className="text-caption text-weak">
                                                    {t(
                                                        'einvoicing.documents.attempts',
                                                        {
                                                            count: row.attempts,
                                                        },
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    {(row.messages.length > 0 || row.error) && (
                                        <ul
                                            className="mt-2 space-y-1 text-caption text-weak"
                                            dir="ltr"
                                        >
                                            {row.error && <li>{row.error}</li>}
                                            {row.messages.map((m, i) => (
                                                <li key={i}>
                                                    {m.code}: {m.message}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="ghost"
                                        >
                                            <a
                                                href={EInvoicingController.pdf.url(
                                                    row.id,
                                                )}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                PDF
                                            </a>
                                        </Button>
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="ghost"
                                        >
                                            <a
                                                href={EInvoicingController.xml.url(
                                                    row.id,
                                                )}
                                            >
                                                XML
                                            </a>
                                        </Button>
                                        {row.can_retry && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                loading={
                                                    busy === `retry-${row.id}`
                                                }
                                                onClick={() =>
                                                    post(
                                                        `retry-${row.id}`,
                                                        EInvoicingController.retry.url(
                                                            row.id,
                                                        ),
                                                    )
                                                }
                                                data-test="einvoice-retry"
                                            >
                                                {t('einvoicing.retry.action')}
                                            </Button>
                                        )}
                                        {row.kind === 'invoice' && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setNoteFor(
                                                        noteFor === row.id
                                                            ? null
                                                            : row.id,
                                                    )
                                                }
                                            >
                                                {t('einvoicing.note.open')}
                                            </Button>
                                        )}
                                    </div>
                                    {noteFor === row.id && (
                                        <NoteForm
                                            row={row}
                                            onDone={() => setNoteFor(null)}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

SettingsEInvoicing.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'einvoicing.title', href: edit() },
    ],
};

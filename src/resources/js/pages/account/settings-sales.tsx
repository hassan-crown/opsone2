import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import SalesSettingsController from '@/actions/App/Http/Controllers/Agreements/SalesSettingsController';
import { bpToPercent, percentToBp } from '@/components/agreement/money';
import { CommissionSettingsSection } from '@/components/commissions/settings';
import type { CommissionSettingsProps } from '@/components/commissions/settings';
import Heading from '@/components/heading';
import {
    CheckboxField,
    RadioGroup,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { home, sales } from '@/routes/settings';

type Role = {
    id: number;
    name: string;
    is_owner: boolean;
    custom_bp: number | null;
    ready_bp: number | null;
    approval_bp: number | null;
};

type Props = {
    settings: {
        vat_rate_bp: number;
        prices_include_vat: boolean;
        quote_validity_days: number;
        contracts_enabled: boolean;
        lock_version: number;
    };
    roles: Role[];
    canEdit: boolean;
    canEditLimits: boolean;
    commissions: CommissionSettingsProps;
    canEditCommissions: boolean;
    cashProofRequired: boolean;
    canEditCashProof: boolean;
};

type LimitKey = 'custom_bp' | 'ready_bp' | 'approval_bp';

/**
 * SettingsSales: VAT and price basis, quote validity, contracts (P-02), discount limits per role (the owner alone;
 * the owner's own role has none) and the commission policy (P-95, P6d). Ready-link settings arrive with P8. Changes
 * never re-price documents already sent nor a pinned sale.
 */
export default function SettingsSales({
    settings,
    roles,
    canEdit,
    canEditLimits,
    commissions,
    canEditCommissions,
    cashProofRequired,
    canEditCashProof,
}: Props) {
    const t = useT();
    const [vat, setVat] = useState(bpToPercent(settings.vat_rate_bp));
    const [inclusive, setInclusive] = useState(
        settings.prices_include_vat ? '1' : '0',
    );
    const [contracts, setContracts] = useState(settings.contracts_enabled);
    const [limits, setLimits] = useState(
        () =>
            Object.fromEntries(
                roles
                    .filter((r) => !r.is_owner)
                    .map((r) => [
                        r.id,
                        {
                            custom_bp: bpToPercent(r.custom_bp),
                            ready_bp: bpToPercent(r.ready_bp),
                            approval_bp: bpToPercent(r.approval_bp),
                        },
                    ]),
            ) as Record<number, Record<LimitKey, string>>,
    );
    const [limitErrors, setLimitErrors] = useState<Record<string, string>>({});
    const [savingLimits, setSavingLimits] = useState(false);
    const [cashProof, setCashProof] = useState(cashProofRequired);
    const [savingCashProof, setSavingCashProof] = useState(false);

    const saveCashProof = () => {
        setSavingCashProof(true);
        router.put(
            SalesSettingsController.updateCashProof.url(),
            {
                cash_proof_required: cashProof,
                lock_version: settings.lock_version,
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingCashProof(false),
            },
        );
    };

    const saveLimits = () => {
        const rows = Object.entries(limits).map(([id, values]) => ({
            role_template_id: Number(id),
            custom_bp: percentToBp(values.custom_bp) ?? -1,
            ready_bp: percentToBp(values.ready_bp) ?? -1,
            approval_bp: percentToBp(values.approval_bp) ?? -1,
        }));
        setSavingLimits(true);
        router.put(
            SalesSettingsController.updateLimits.url(),
            { limits: rows },
            {
                preserveScroll: true,
                onError: (errors) => setLimitErrors(errors),
                onSuccess: () => setLimitErrors({}),
                onFinish: () => setSavingLimits(false),
            },
        );
    };

    return (
        <>
            <Head title={t('agreements.settings.title')} />
            <PageHeader
                title={t('agreements.settings.title')}
                description={t('agreements.settings.description')}
            />
            {!canEdit && (
                <Alert variant="info" className="mb-6 max-w-2xl">
                    <AlertBody>
                        <AlertDescription>
                            {t('agreements.settings.view_only')}
                        </AlertDescription>
                    </AlertBody>
                </Alert>
            )}

            <Form
                {...SalesSettingsController.update.form()}
                options={{ preserveScroll: true }}
                transform={(data) => ({
                    ...data,
                    vat_rate_bp: percentToBp(vat) ?? -1,
                    prices_include_vat: inclusive === '1',
                    contracts_enabled: contracts,
                    lock_version: settings.lock_version,
                })}
                className="max-w-2xl space-y-8"
                data-test="sales-settings-form"
            >
                {({ processing, errors }) => (
                    <>
                        <fieldset
                            disabled={!canEdit}
                            className="m-0 min-w-0 space-y-6 border-0 p-0"
                        >
                            <Heading
                                variant="small"
                                title={t('agreements.settings.vat')}
                                description={t('agreements.settings.vat_hint')}
                            />
                            <TextField
                                label={t('agreements.settings.vat_rate')}
                                value={vat}
                                inputMode="decimal"
                                dir="ltr"
                                required
                                error={errors.vat_rate_bp}
                                onChange={(e) => setVat(e.target.value)}
                            />
                            <RadioGroup
                                legend={t('agreements.settings.prices')}
                                name="prices_include_vat"
                                value={inclusive}
                                onChange={setInclusive}
                                options={[
                                    {
                                        value: '1',
                                        label: t('agreements.vat.inclusive'),
                                    },
                                    {
                                        value: '0',
                                        label: t('agreements.vat.exclusive'),
                                    },
                                ]}
                                error={errors.prices_include_vat}
                            />
                            <Heading
                                variant="small"
                                title={t('agreements.settings.quotes')}
                            />
                            <TextField
                                label={t('agreements.settings.validity')}
                                hint={t('agreements.settings.validity_hint')}
                                name="quote_validity_days"
                                type="number"
                                min={1}
                                max={365}
                                dir="ltr"
                                defaultValue={settings.quote_validity_days}
                                required
                                error={errors.quote_validity_days}
                            />
                            <CheckboxField
                                label={t('agreements.settings.contracts')}
                                hint={t('agreements.settings.contracts_hint')}
                                checked={contracts}
                                onChange={(e) => setContracts(e.target.checked)}
                                error={errors.contracts_enabled}
                            />
                            {errors.lock_version && (
                                <Alert variant="danger" role="alert">
                                    <AlertBody>{errors.lock_version}</AlertBody>
                                </Alert>
                            )}
                        </fieldset>
                        {canEdit && (
                            <Button
                                type="submit"
                                loading={processing}
                                data-test="save-sales-settings"
                            >
                                {processing && <Spinner />}
                                {t('ui.save')}
                            </Button>
                        )}
                    </>
                )}
            </Form>

            <section
                className="mt-10 max-w-3xl space-y-4"
                data-test="discount-limits"
            >
                <Heading
                    variant="small"
                    title={t('agreements.settings.limits')}
                    description={t('agreements.settings.limits_hint')}
                />
                <TableWrap>
                    <Table caption={t('agreements.settings.limits')}>
                        <thead>
                            <tr>
                                <Th>{t('agreements.settings.role')}</Th>
                                <Th>{t('agreements.settings.custom')}</Th>
                                <Th>{t('agreements.settings.ready')}</Th>
                                <Th>{t('agreements.settings.approval')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {roles.map((role) => (
                                <tr key={role.id}>
                                    <Td className="font-semibold">
                                        {role.name}
                                    </Td>
                                    {(
                                        [
                                            'custom_bp',
                                            'ready_bp',
                                            'approval_bp',
                                        ] as const
                                    ).map((field) => (
                                        <Td key={field}>
                                            {role.is_owner ? (
                                                <span className="text-weak">
                                                    {t(
                                                        'agreements.discount.no_limit',
                                                    )}
                                                </span>
                                            ) : (
                                                <TextField
                                                    label={`${role.name} — ${t(`agreements.settings.${field === 'custom_bp' ? 'custom' : field === 'ready_bp' ? 'ready' : 'approval'}`)}`}
                                                    className="[&_label]:sr-only"
                                                    value={
                                                        limits[role.id]?.[
                                                            field
                                                        ] ?? ''
                                                    }
                                                    inputMode="decimal"
                                                    dir="ltr"
                                                    disabled={!canEditLimits}
                                                    onChange={(e) =>
                                                        setLimits((all) => ({
                                                            ...all,
                                                            [role.id]: {
                                                                ...all[role.id],
                                                                [field]:
                                                                    e.target
                                                                        .value,
                                                            },
                                                        }))
                                                    }
                                                />
                                            )}
                                        </Td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </TableWrap>
                {Object.keys(limitErrors).length > 0 && (
                    <Alert variant="danger" role="alert">
                        <AlertBody>
                            {t('agreements.settings.limits_invalid')}
                        </AlertBody>
                    </Alert>
                )}
                {canEditLimits ? (
                    <Button
                        type="button"
                        onClick={saveLimits}
                        loading={savingLimits}
                        data-test="save-limits"
                    >
                        {t('agreements.settings.save_limits')}
                    </Button>
                ) : (
                    <p className="text-caption text-weak">
                        {t('agreements.settings.limits_owner_only')}
                    </p>
                )}
                <p className="text-caption text-weak">
                    {t('agreements.settings.no_recalc')}
                </p>
            </section>
            <section
                className="mt-10 max-w-2xl space-y-4"
                data-test="cash-proof"
            >
                <Heading
                    variant="small"
                    title={t('agreements.settings.cash_proof')}
                    description={t('agreements.settings.cash_proof_hint')}
                />
                <CheckboxField
                    label={t('agreements.settings.cash_proof_required')}
                    checked={cashProof}
                    disabled={!canEditCashProof}
                    onChange={(e) => setCashProof(e.target.checked)}
                    data-test="cash-proof-required"
                />
                {canEditCashProof ? (
                    <Button
                        type="button"
                        onClick={saveCashProof}
                        loading={savingCashProof}
                        disabled={cashProof === cashProofRequired}
                        data-test="save-cash-proof"
                    >
                        {t('ui.save')}
                    </Button>
                ) : (
                    <p className="text-caption text-weak">
                        {t('agreements.settings.limits_owner_only')}
                    </p>
                )}
            </section>
            <CommissionSettingsSection
                settings={commissions}
                canEdit={canEditCommissions}
            />
        </>
    );
}

SettingsSales.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'agreements.settings.title', href: sales() },
    ],
};

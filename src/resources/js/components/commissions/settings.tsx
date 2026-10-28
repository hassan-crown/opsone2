import { router } from '@inertiajs/react';
import { useState } from 'react';
import SalesSettingsController from '@/actions/App/Http/Controllers/Agreements/SalesSettingsController';
import { bpToPercent, percentToBp } from '@/components/agreement/money';
import Heading from '@/components/heading';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

export type CommissionSettingsProps = {
    enabled: boolean;
    default_rate_bp: number;
    manual_lines_eligible: boolean;
    lock_version: number;
    roles: { id: number; name: string; rate_bp: number | null }[];
    members: { id: number; name: string; rate_bp: number | null }[];
};

type Rates = Record<number, string>;

const toRows = (rates: Rates, key: 'role_template_id' | 'user_id') =>
    Object.entries(rates).map(([id, text]) => ({
        [key]: Number(id),
        rate_bp: text.trim() === '' ? null : (percentToBp(text) ?? -1),
    }));

/**
 * SettingsSales commission part (SAL-10, SAL-19, P-95): off with 0% by default; an optional rate per role or member
 * (empty = none). Applies to sales pinned from now on — a pinned sale keeps its rate.
 */
export function CommissionSettingsSection({
    settings,
    canEdit,
}: {
    settings: CommissionSettingsProps;
    canEdit: boolean;
}) {
    const t = useT();
    const [enabled, setEnabled] = useState(settings.enabled);
    const [manual, setManual] = useState(settings.manual_lines_eligible);
    const [rate, setRate] = useState(bpToPercent(settings.default_rate_bp));
    const [roles, setRoles] = useState<Rates>(() =>
        Object.fromEntries(
            settings.roles.map((r) => [r.id, bpToPercent(r.rate_bp)]),
        ),
    );
    const [members, setMembers] = useState<Rates>(() =>
        Object.fromEntries(
            settings.members.map((m) => [m.id, bpToPercent(m.rate_bp)]),
        ),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.put(
            SalesSettingsController.updateCommissions.url(),
            {
                enabled,
                default_rate_bp: percentToBp(rate) ?? -1,
                manual_lines_eligible: manual,
                roles: toRows(roles, 'role_template_id'),
                members: toRows(members, 'user_id'),
                lock_version: settings.lock_version,
            },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e),
                onSuccess: () => setErrors({}),
                onFinish: () => setSaving(false),
            },
        );
    };

    const rateTable = (
        caption: string,
        rows: { id: number; name: string }[],
        values: Rates,
        set: (next: Rates) => void,
    ) => (
        <TableWrap>
            <Table caption={caption}>
                <thead>
                    <tr>
                        <Th>{caption}</Th>
                        <Th>{t('commissions.settings.rate')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id}>
                            <Td className="font-semibold">{row.name}</Td>
                            <Td>
                                <TextField
                                    label={`${row.name} — ${t('commissions.settings.rate')}`}
                                    className="max-w-32 [&_label]:sr-only"
                                    value={values[row.id] ?? ''}
                                    placeholder={t('commissions.settings.none')}
                                    inputMode="decimal"
                                    dir="ltr"
                                    disabled={!canEdit}
                                    onChange={(e) =>
                                        set({
                                            ...values,
                                            [row.id]: e.target.value,
                                        })
                                    }
                                />
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
        </TableWrap>
    );

    return (
        <section
            className="mt-10 max-w-3xl space-y-4"
            data-test="commission-settings"
        >
            <Heading
                variant="small"
                title={t('commissions.settings.title')}
                description={t('commissions.settings.hint')}
            />
            <fieldset
                disabled={!canEdit}
                className="m-0 min-w-0 space-y-4 border-0 p-0"
            >
                <CheckboxField
                    label={t('commissions.settings.enabled')}
                    checked={enabled}
                    onChange={(e) => setEnabled(e.target.checked)}
                    error={errors.enabled}
                />
                <TextField
                    label={t('commissions.settings.default_rate')}
                    hint={t('commissions.settings.default_hint')}
                    className="max-w-xs"
                    value={rate}
                    inputMode="decimal"
                    dir="ltr"
                    error={errors.default_rate_bp}
                    onChange={(e) => setRate(e.target.value)}
                    data-test="default-rate"
                />
                <CheckboxField
                    label={t('commissions.settings.manual_lines')}
                    hint={t('commissions.settings.manual_hint')}
                    checked={manual}
                    onChange={(e) => setManual(e.target.checked)}
                />
            </fieldset>
            {rateTable(
                t('commissions.settings.by_role'),
                settings.roles,
                roles,
                setRoles,
            )}
            {settings.members.length > 0 &&
                rateTable(
                    t('commissions.settings.by_member'),
                    settings.members,
                    members,
                    setMembers,
                )}
            {Object.keys(errors).length > 0 && (
                <Alert variant="danger" role="alert">
                    <AlertBody>{t('commissions.settings.invalid')}</AlertBody>
                </Alert>
            )}
            {canEdit ? (
                <Button
                    type="button"
                    onClick={save}
                    loading={saving}
                    data-test="save-commissions"
                >
                    {t('commissions.settings.save')}
                </Button>
            ) : (
                <p className="text-caption text-weak">
                    {t('commissions.settings.p95_only')}
                </p>
            )}
            <p className="text-caption text-weak">
                {t('commissions.settings.no_retro')}
            </p>
        </section>
    );
}

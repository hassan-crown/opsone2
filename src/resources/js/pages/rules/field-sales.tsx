import { Head } from '@inertiajs/react';
import { useState } from 'react';
import FieldSalesSettingsController from '@/actions/App/Http/Controllers/FieldSales/FieldSalesSettingsController';
import Heading from '@/components/heading';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { StickyActions } from '@/components/opsone/sticky-actions';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes/settings';

type Settings = {
    enabled: boolean;
    kinds: string[];
    response_hours: number | null;
    max_discount_bp: number;
    lock_version: number | null;
};

type Props = { settings: Settings; allKinds: string[]; canEdit: boolean };

/**
 * Settings › field sales (ADR-0041): turn field sales on, choose which appointment kinds reps handle in the field app
 * and the response time of a dispatched lead. A draft until published as a configuration release (ADR-0040).
 */
export default function FieldSalesSettings({
    settings,
    allKinds,
    canEdit,
}: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [form, setForm] = useState(settings);

    const toggleKind = (kind: string, on: boolean) =>
        setForm({
            ...form,
            kinds: on
                ? [...form.kinds, kind]
                : form.kinds.filter((k) => k !== kind),
        });

    return (
        <>
            <Head title={t('rules.field_sales.title')} />
            <PageHeader
                title={t('rules.field_sales.title')}
                description={t('rules.field_sales.description')}
            />
            <RulesNav current="field_sales" canEdit={canEdit} />

            <fieldset
                disabled={!canEdit}
                className="m-0 max-w-2xl min-w-0 space-y-6 border-0 p-0"
                data-test="field-sales-settings"
            >
                <CheckboxField
                    label={t('rules.field_sales.enabled')}
                    hint={t('rules.field_sales.enabled_hint')}
                    checked={form.enabled}
                    onChange={(e) =>
                        setForm({ ...form, enabled: e.target.checked })
                    }
                />

                <Heading
                    variant="small"
                    title={t('rules.field_sales.kinds')}
                    description={t('rules.field_sales.kinds_hint')}
                />
                <div className="grid gap-x-4 sm:grid-cols-2">
                    {allKinds.map((kind) => (
                        <CheckboxField
                            key={kind}
                            label={t(
                                `appointments.kind.${kind}` as TranslationKey,
                            )}
                            checked={form.kinds.includes(kind)}
                            onChange={(e) => toggleKind(kind, e.target.checked)}
                        />
                    ))}
                </div>
                {(write.errors.kinds ?? write.errors['kinds.0']) && (
                    <p className="text-caption text-danger-text">
                        {write.errors.kinds ?? write.errors['kinds.0']}
                    </p>
                )}

                <TextField
                    label={t('rules.field_sales.response_hours')}
                    hint={t('rules.field_sales.response_hours_hint')}
                    type="number"
                    dir="ltr"
                    min={1}
                    max={720}
                    value={
                        form.response_hours === null
                            ? ''
                            : String(form.response_hours)
                    }
                    error={write.errors.response_hours}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            response_hours:
                                e.target.value === ''
                                    ? null
                                    : Number(e.target.value),
                        })
                    }
                />
                <TextField
                    label={t('rules.field_sales.max_discount')}
                    hint={t('rules.field_sales.max_discount_hint')}
                    type="number"
                    dir="ltr"
                    min={0}
                    max={50}
                    step="0.5"
                    value={String(form.max_discount_bp / 100)}
                    error={write.errors.max_discount_bp}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            max_discount_bp: Math.round(
                                Number(e.target.value || 0) * 100,
                            ),
                        })
                    }
                />
                {canEdit && (
                    <StickyActions>
                        <Button
                            type="button"
                            onClick={() =>
                                write.send(
                                    'put',
                                    FieldSalesSettingsController.update().url,
                                    { ...form },
                                )
                            }
                            loading={write.busy}
                            data-test="save-field-sales-settings"
                        >
                            {t('rules.save')}
                        </Button>
                    </StickyActions>
                )}
            </fieldset>
        </>
    );
}

FieldSalesSettings.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        {
            title: 'rules.field_sales.title',
            href: FieldSalesSettingsController.edit(),
        },
    ],
};

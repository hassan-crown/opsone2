import { Form, Head } from '@inertiajs/react';
import SetupController from '@/actions/App/Http/Controllers/Org/SetupController';
import { SelectField, TextField } from '@/components/opsone/field';
import { StepFrame } from '@/components/org/step-frame';
import { useT } from '@/lib/i18n';

type Props = {
    facility: {
        name_ar: string;
        name_en: string | null;
        default_locale: string;
        currency: string;
        currency_locked: boolean;
        timezone: string;
    };
    currencies: string[];
    timezones: string[];
};

export default function SetupOrg({ facility, currencies, timezones }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('org.setup.step_org')} />
            <StepFrame
                step="org"
                description={t('org.setup.org_intro')}
                where="org.setup.where_org"
                formId="setup-org-form"
                nextLabel={t('org.setup.save_next')}
            >
                <Form
                    {...SetupController.saveOrg.form()}
                    id="setup-org-form"
                    className="grid gap-5 md:grid-cols-2"
                >
                    {({ errors }) => (
                        <>
                            <TextField
                                label={t('org.setup.name_ar')}
                                name="name_ar"
                                defaultValue={facility.name_ar}
                                required
                                error={errors.name_ar}
                            />
                            <TextField
                                label={t('org.setup.name_en')}
                                name="name_en"
                                dir="ltr"
                                defaultValue={facility.name_en ?? ''}
                                optional
                                error={errors.name_en}
                            />
                            <SelectField
                                label={t('org.setup.default_locale')}
                                name="default_locale"
                                defaultValue={facility.default_locale}
                                options={[
                                    { value: 'ar', label: t('org.lang.ar') },
                                    { value: 'en', label: t('org.lang.en') },
                                ]}
                                error={errors.default_locale}
                            />
                            <SelectField
                                label={t('org.setup.currency')}
                                name="currency"
                                defaultValue={facility.currency}
                                hint={
                                    facility.currency_locked
                                        ? t('org.setup.currency_locked')
                                        : t('org.setup.currency_hint')
                                }
                                options={currencies.map((c) => ({
                                    value: c,
                                    label: c,
                                    disabled:
                                        facility.currency_locked &&
                                        c !== facility.currency,
                                }))}
                                error={errors.currency}
                            />
                            <SelectField
                                label={t('org.setup.timezone')}
                                name="timezone"
                                defaultValue={facility.timezone}
                                options={timezones.map((z) => ({
                                    value: z,
                                    label: z,
                                }))}
                                error={errors.timezone}
                            />
                        </>
                    )}
                </Form>
            </StepFrame>
        </>
    );
}

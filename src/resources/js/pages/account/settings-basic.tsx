import { Form, Head } from '@inertiajs/react';
import FacilityProfileController from '@/actions/App/Http/Controllers/Account/FacilityProfileController';
import Heading from '@/components/heading';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { basic, home } from '@/routes/settings';

type Props = {
    facility: {
        name_ar: string;
        name_en: string | null;
        contact_email: string | null;
        contact_phone: string | null;
        address: string | null;
        default_locale: 'ar' | 'en';
        timezone: string;
        currency: string;
        currency_locked: boolean;
        version: string | null;
    };
    timezones: string[];
    currencies: string[];
    canEdit: boolean;
};

export default function SettingsBasic({
    facility,
    timezones,
    currencies,
    canEdit,
}: Props) {
    const t = useT();
    const readOnly = !canEdit;

    return (
        <>
            <Head title={t('account.profile.title')} />

            <PageHeader
                title={t('account.profile.title')}
                description={t('account.profile.description')}
            />

            {readOnly && (
                <Alert variant="info" className="mb-6 max-w-2xl">
                    <AlertBody>
                        <AlertDescription>
                            {t('account.profile.view_only')}
                        </AlertDescription>
                    </AlertBody>
                </Alert>
            )}

            <Form
                {...FacilityProfileController.update.form()}
                options={{ preserveScroll: true }}
                className="max-w-2xl space-y-10"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="version"
                            value={facility.version ?? ''}
                        />
                        {errors.version && (
                            <Alert variant="danger">
                                <AlertBody>
                                    <AlertDescription>
                                        {errors.version}
                                    </AlertDescription>
                                </AlertBody>
                            </Alert>
                        )}

                        <fieldset
                            disabled={readOnly}
                            className="space-y-6 border-0 p-0"
                        >
                            <Heading
                                variant="small"
                                title={t('account.profile.names')}
                            />
                            <div className="grid gap-6 sm:grid-cols-2">
                                <TextField
                                    label={t('account.profile.name_ar')}
                                    name="name_ar"
                                    defaultValue={facility.name_ar}
                                    dir="rtl"
                                    required
                                    maxLength={150}
                                    error={errors.name_ar}
                                />
                                <TextField
                                    label={t('account.profile.name_en')}
                                    name="name_en"
                                    defaultValue={facility.name_en ?? ''}
                                    dir="ltr"
                                    optional
                                    maxLength={150}
                                    error={errors.name_en}
                                />
                            </div>

                            <Heading
                                variant="small"
                                title={t('account.profile.contact')}
                            />
                            <div className="grid gap-6 sm:grid-cols-2">
                                <TextField
                                    label={t('account.profile.contact_email')}
                                    name="contact_email"
                                    type="email"
                                    dir="ltr"
                                    inputMode="email"
                                    autoComplete="off"
                                    defaultValue={facility.contact_email ?? ''}
                                    optional
                                    error={errors.contact_email}
                                />
                                <TextField
                                    label={t('account.profile.contact_phone')}
                                    name="contact_phone"
                                    type="tel"
                                    dir="ltr"
                                    inputMode="tel"
                                    autoComplete="off"
                                    defaultValue={facility.contact_phone ?? ''}
                                    optional
                                    error={errors.contact_phone}
                                />
                            </div>
                            <TextareaField
                                label={t('account.profile.address')}
                                name="address"
                                rows={3}
                                maxLength={500}
                                defaultValue={facility.address ?? ''}
                                optional
                                error={errors.address}
                            />

                            <Heading
                                variant="small"
                                title={t('account.profile.defaults')}
                            />
                            <div className="grid gap-6 sm:grid-cols-2">
                                <SelectField
                                    label={t('account.profile.default_locale')}
                                    hint={t(
                                        'account.profile.default_locale_hint',
                                    )}
                                    name="default_locale"
                                    defaultValue={facility.default_locale}
                                    required
                                    options={[
                                        {
                                            value: 'ar',
                                            label: t(
                                                'account.profile.locale.ar',
                                            ),
                                        },
                                        {
                                            value: 'en',
                                            label: t(
                                                'account.profile.locale.en',
                                            ),
                                        },
                                    ]}
                                    error={errors.default_locale}
                                />
                                <SelectField
                                    label={t('account.profile.timezone')}
                                    hint={t('account.profile.timezone_hint')}
                                    name="timezone"
                                    defaultValue={facility.timezone}
                                    required
                                    options={timezones.map((zone) => ({
                                        value: zone,
                                        label: zone,
                                    }))}
                                    error={errors.timezone}
                                />
                                {facility.currency_locked ? (
                                    <>
                                        <input
                                            type="hidden"
                                            name="currency"
                                            value={facility.currency}
                                        />
                                        <TextField
                                            label={t(
                                                'account.profile.currency',
                                            )}
                                            value={facility.currency}
                                            dir="ltr"
                                            readOnly
                                            hint={t(
                                                'account.profile.currency_locked',
                                            )}
                                        />
                                    </>
                                ) : (
                                    <SelectField
                                        label={t('account.profile.currency')}
                                        hint={t(
                                            'account.profile.currency_hint',
                                        )}
                                        name="currency"
                                        defaultValue={facility.currency}
                                        required
                                        options={currencies.map((code) => ({
                                            value: code,
                                            label: code,
                                        }))}
                                        error={errors.currency}
                                    />
                                )}
                            </div>
                        </fieldset>

                        {canEdit && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    loading={processing}
                                    data-test="save-facility-profile"
                                >
                                    {processing && <Spinner />}
                                    {t('ui.save')}
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </Form>
        </>
    );
}

SettingsBasic.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'account.profile.title', href: basic() },
    ],
};

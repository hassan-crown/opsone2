import { Form, Head, Link } from '@inertiajs/react';
import SupportRequestController from '@/actions/App/Http/Controllers/Help/SupportRequestController';
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
import type { TranslationKey } from '@/lib/i18n';
import { index as helpIndex } from '@/routes/help';

type Props = {
    categories: string[];
    pagePath: string | null;
    canGrantAccess: boolean;
};

export default function SupportRequest({
    categories,
    pagePath,
    canGrantAccess,
}: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('help.support.title')} />
            <PageHeader
                title={t('help.support.title')}
                description={t('help.support.description')}
            />

            <Form
                {...SupportRequestController.store.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="max-w-2xl space-y-6"
            >
                {({ processing, errors }) => (
                    <>
                        {pagePath && (
                            <>
                                <input
                                    type="hidden"
                                    name="page_path"
                                    value={pagePath}
                                />
                                <p className="text-caption text-weak" dir="ltr">
                                    {t('help.support.page', { path: pagePath })}
                                </p>
                            </>
                        )}
                        <SelectField
                            label={t('help.support.category')}
                            name="category"
                            defaultValue="question"
                            required
                            options={categories.map((key) => ({
                                value: key,
                                label: t(
                                    `help.support.category.${key}` as TranslationKey,
                                ),
                            }))}
                            error={errors.category}
                        />
                        <TextField
                            label={t('help.support.subject')}
                            name="subject"
                            required
                            minLength={5}
                            maxLength={150}
                            error={errors.subject}
                        />
                        <TextareaField
                            label={t('help.support.message')}
                            hint={t('help.support.message_hint')}
                            name="message"
                            rows={6}
                            required
                            minLength={10}
                            maxLength={5000}
                            error={errors.message}
                        />
                        {canGrantAccess && (
                            <Alert variant="info">
                                <AlertBody>
                                    <AlertDescription>
                                        {t('help.support.access_note')}
                                    </AlertDescription>
                                </AlertBody>
                            </Alert>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="support-submit"
                            >
                                {processing && <Spinner />}
                                {t('help.support.submit')}
                            </Button>
                            <Button variant="ghost" asChild>
                                <Link href={helpIndex()}>{t('ui.cancel')}</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

SupportRequest.layout = {
    breadcrumbs: [
        { title: 'help.title', href: helpIndex() },
        {
            title: 'help.support.title',
            href: SupportRequestController.create(),
        },
    ],
};

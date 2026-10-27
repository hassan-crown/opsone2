import { Form, Head, Link } from '@inertiajs/react';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import type { InterestRow, Option } from '@/components/customers/types';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Prefill = {
    customer_id: number;
    name: string;
    mobile: string;
    email: string | null;
    branch_id: number;
};

type Props = {
    interest: InterestRow | null;
    prefill: Prefill | null;
    branches: Option[];
    members: Option[];
};

const CHANNELS = [
    'phone',
    'whatsapp',
    'email',
    'walk_in',
    'website',
    'other',
] as const;

/** Record or edit an interest (P-09). A mobile or an email is enough; conversion needs the mobile. */
export default function InterestForm({
    interest,
    prefill,
    branches,
    members,
}: Props) {
    const t = useT();
    const editing = interest !== null;
    const target = interest
        ? InterestController.update.form(interest.id)
        : InterestController.store.form();
    const title = editing
        ? t('interests.form.edit_title')
        : t('interests.form.new_title');
    const defaultBranch = prefill?.branch_id ?? branches[0]?.id;

    return (
        <>
            <Head title={title} />
            <PageHeader title={title} />
            <Form
                {...target}
                className="grid max-w-3xl gap-6"
                data-test="interest-form"
            >
                {({ errors, processing }) => (
                    <>
                        <Card>
                            {prefill && (
                                <input
                                    type="hidden"
                                    name="customer_id"
                                    value={prefill.customer_id}
                                />
                            )}
                            <TextField
                                label={t('interests.form.name')}
                                name="name"
                                defaultValue={
                                    interest?.name ?? prefill?.name ?? ''
                                }
                                error={errors.name}
                                required
                                autoFocus
                            />
                            <TextField
                                label={t('interests.form.mobile')}
                                hint={t('interests.form.contact_hint')}
                                name="mobile"
                                type="tel"
                                dir="ltr"
                                defaultValue={
                                    interest?.mobile ?? prefill?.mobile ?? ''
                                }
                                error={errors.mobile}
                                optional
                            />
                            <TextField
                                label={t('interests.form.email')}
                                name="email"
                                type="email"
                                dir="ltr"
                                defaultValue={
                                    interest?.email ?? prefill?.email ?? ''
                                }
                                error={errors.email}
                                optional
                            />
                            <TextareaField
                                label={t('interests.form.need')}
                                name="need"
                                defaultValue={interest?.need ?? ''}
                                error={errors.need}
                                required
                            />
                            <SelectField
                                label={t('interests.form.channel')}
                                name="channel"
                                defaultValue={interest?.channel ?? 'phone'}
                                options={CHANNELS.map((c) => ({
                                    value: c,
                                    label: t(
                                        `interests.channel.${c}` as TranslationKey,
                                    ),
                                }))}
                                error={errors.channel}
                                required
                            />
                            {!editing && (
                                <SelectField
                                    label={t('interests.form.branch')}
                                    name="branch_id"
                                    defaultValue={
                                        defaultBranch
                                            ? String(defaultBranch)
                                            : ''
                                    }
                                    options={branches.map((b) => ({
                                        value: String(b.id),
                                        label: b.name,
                                    }))}
                                    error={errors.branch_id}
                                    required
                                />
                            )}
                            <SelectField
                                label={t('interests.form.assignee')}
                                name="assignee_id"
                                defaultValue={
                                    interest?.assignee_id
                                        ? String(interest.assignee_id)
                                        : ''
                                }
                                options={[
                                    {
                                        value: '',
                                        label: t('interests.form.no_assignee'),
                                    },
                                    ...members.map((m) => ({
                                        value: String(m.id),
                                        label: m.name,
                                    })),
                                ]}
                                error={errors.assignee_id}
                                optional
                            />
                            {errors.customer_id && (
                                <p
                                    role="alert"
                                    className="text-caption text-danger-text"
                                >
                                    {errors.customer_id}
                                </p>
                            )}
                        </Card>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                loading={processing}
                                data-test="interest-save"
                            >
                                {t('interests.form.save')}
                            </Button>
                            <Button asChild variant="ghost">
                                <Link
                                    href={
                                        interest
                                            ? InterestController.show(
                                                  interest.id,
                                              )
                                            : InterestController.index()
                                    }
                                >
                                    {t('interests.form.cancel')}
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

InterestForm.layout = {
    breadcrumbs: [{ title: 'interests.nav', href: InterestController.index() }],
};

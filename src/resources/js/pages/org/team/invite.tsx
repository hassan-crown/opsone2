import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Check, Info } from 'lucide-react';
import type { FormEvent } from 'react';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import type { Seats } from '@/components/org/format';
import { RoleRows } from '@/components/org/role-rows';
import type { RoleRow } from '@/components/org/role-rows';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = {
    roles: { id: number; name: string }[];
    branches: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    scopes: string[];
    seats: Seats;
};

export default function TeamInvite({
    roles,
    branches,
    departments,
    scopes,
    seats,
}: Props) {
    const t = useT();
    const form = useForm<{
        name: string;
        email: string;
        job_title: string;
        department_id: string;
        branch_ids: number[];
        roles: RoleRow[];
    }>({
        name: '',
        email: '',
        job_title: '',
        department_id: '',
        branch_ids: branches.length === 1 ? [branches[0].id] : [],
        roles: [{ role_template_id: roles[0]?.id ?? '', scope: '' }],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(TeamController.store.url());
    };

    const toggleBranch = (id: number, checked: boolean) =>
        form.setData(
            'branch_ids',
            checked
                ? [...form.data.branch_ids, id]
                : form.data.branch_ids.filter((b) => b !== id),
        );

    const full = seats.limit !== null && seats.used >= seats.limit;

    return (
        <>
            <Head title={t('org.invite.title')} />
            <PageHeader
                title={t('org.invite.title')}
                description={t('org.invite.description')}
                back={
                    <Link
                        href={TeamController.index.url()}
                        className="text-label text-link no-underline hover:text-brand-hover"
                    >
                        {t('org.member.back')}
                    </Link>
                }
            />
            {(errors.license || full) && (
                <Alert
                    variant={errors.license ? 'danger' : 'warning'}
                    className="mb-4"
                    data-test="seat-limit"
                >
                    <AlertBody>
                        <AlertTitle>{t('org.invite.limit_title')}</AlertTitle>
                        {errors.license ?? t('org.invite.note_full')}
                    </AlertBody>
                </Alert>
            )}
            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <Card>
                    <form
                        onSubmit={submit}
                        className="grid gap-5"
                        data-test="invite-form"
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <TextField
                                label={t('org.invite.name')}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                                error={errors.name}
                            />
                            <TextField
                                label={t('org.invite.email')}
                                type="email"
                                dir="ltr"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                hint={t('org.invite.email_hint')}
                                required
                                error={errors.email}
                            />
                            <TextField
                                label={t('org.invite.job_title')}
                                value={form.data.job_title}
                                onChange={(e) =>
                                    form.setData('job_title', e.target.value)
                                }
                                optional
                                error={errors.job_title}
                            />
                            <SelectField
                                label={t('org.invite.department')}
                                value={form.data.department_id}
                                onChange={(e) =>
                                    form.setData(
                                        'department_id',
                                        e.target.value,
                                    )
                                }
                                optional
                                options={[
                                    {
                                        value: '',
                                        label: t('org.invite.no_department'),
                                    },
                                    ...departments.map((d) => ({
                                        value: String(d.id),
                                        label: d.name,
                                    })),
                                ]}
                                error={errors.department_id}
                            />
                        </div>

                        <fieldset className="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                            <legend className="mb-1 p-0 text-label text-strong">
                                {t('org.invite.placement')}
                            </legend>
                            <p className="text-caption text-weak">
                                {t('org.invite.branches_hint')}
                            </p>
                            <div className="grid gap-x-6 sm:grid-cols-2">
                                {branches.map((branch) => (
                                    <CheckboxField
                                        key={branch.id}
                                        label={branch.name}
                                        checked={form.data.branch_ids.includes(
                                            branch.id,
                                        )}
                                        onChange={(e) =>
                                            toggleBranch(
                                                branch.id,
                                                e.target.checked,
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            {(errors.branch_ids || errors['branch_ids.0']) && (
                                <p
                                    role="alert"
                                    className="text-caption text-danger-text"
                                >
                                    {errors.branch_ids ??
                                        errors['branch_ids.0']}
                                </p>
                            )}
                        </fieldset>

                        <div className="flex flex-col gap-3">
                            <h2 className="text-label text-strong">
                                {t('org.invite.access')}
                            </h2>
                            <RoleRows
                                rows={form.data.roles}
                                onChange={(rows) => form.setData('roles', rows)}
                                roleOptions={roles}
                                scopes={scopes}
                                errors={errors}
                            />
                        </div>

                        <div className="flex flex-wrap justify-end gap-2">
                            <Button asChild variant="ghost">
                                <Link href={TeamController.index.url()}>
                                    {t('ui.cancel')}
                                </Link>
                            </Button>
                            <Button
                                loading={form.processing}
                                data-test="invite-send"
                            >
                                {t('org.invite.send')}
                                <Icon icon={ArrowRight} />
                            </Button>
                        </div>
                    </form>
                </Card>
                <Card className="gap-3">
                    <h2 className="text-label text-strong">
                        {t('org.invite.on_send')}
                    </h2>
                    <ul className="m-0 flex list-none flex-col gap-3 p-0 text-body text-strong">
                        <li className="flex items-start gap-2">
                            <Icon
                                icon={Check}
                                size="sm"
                                className="mt-1 text-success-text"
                            />
                            {seats.limit === null
                                ? t('org.invite.note_seat_unlimited')
                                : t('org.invite.note_seat', {
                                      next: seats.used + 1,
                                      limit: seats.limit,
                                  })}
                        </li>
                        {(
                            [
                                'org.invite.note_link',
                                'org.invite.note_scope',
                                'org.invite.note_full',
                            ] as const
                        ).map((key) => (
                            <li key={key} className="flex items-start gap-2">
                                <Icon
                                    icon={Info}
                                    size="sm"
                                    className="mt-1 text-weak"
                                />
                                {t(key)}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </>
    );
}

TeamInvite.layout = {
    breadcrumbs: [
        { title: 'org.team.title', href: TeamController.index() },
        { title: 'org.invite.title', href: TeamController.create() },
    ],
};

import { Form, Head } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { Icon } from '@/components/opsone/icon';
import { SelectField, TextField } from '@/components/opsone/field';
import { StepFrame } from '@/components/org/step-frame';
import type { Seats } from '@/components/org/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type Props = {
    invitations: {
        id: number;
        name: string | null;
        email: string;
        roles: string[];
    }[];
    roles: { id: number; name: string }[];
    branches: { id: number; name: string }[];
    seats: Seats;
    canInvite: boolean;
};

export default function SetupTeam({
    invitations,
    roles,
    branches,
    seats,
    canInvite,
}: Props) {
    const t = useT();
    const [formKey, setFormKey] = useState(0);

    return (
        <>
            <Head title={t('org.setup.step_team')} />
            <StepFrame
                step="team"
                description={t('org.setup.team_intro')}
                where="org.setup.where_team"
            >
                <div className="flex flex-col gap-5">
                    {invitations.length === 0 ? (
                        <p className="text-body text-weak">
                            {t('org.setup.team_none')}
                        </p>
                    ) : (
                        <ul className="m-0 flex list-none flex-col divide-y divide-stroke-weak rounded-md border border-stroke-weak p-0">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                                >
                                    <span className="flex min-w-0 flex-col">
                                        <span className="font-semibold text-strong">
                                            {invitation.name ??
                                                invitation.email}
                                        </span>
                                        <span className="text-caption text-weak">
                                            {invitation.roles.join('، ')}
                                        </span>
                                    </span>
                                    <Badge variant="warning">
                                        {t('org.status.pending')}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    )}

                    {canInvite && (
                        <Form
                            {...TeamController.store.form()}
                            key={formKey}
                            data-test="wizard-invite-form"
                            className="grid gap-4 md:grid-cols-2"
                            onSuccess={() => setFormKey((k) => k + 1)}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="from"
                                        value="wizard"
                                    />
                                    {errors.license && (
                                        <p
                                            role="alert"
                                            className="text-body text-danger-text md:col-span-2"
                                        >
                                            <strong>
                                                {t('org.invite.limit_title')}
                                                :{' '}
                                            </strong>
                                            {errors.license}
                                        </p>
                                    )}
                                    <TextField
                                        label={t('org.invite.name')}
                                        name="name"
                                        required
                                        error={errors.name}
                                    />
                                    <TextField
                                        label={t('org.invite.email')}
                                        name="email"
                                        type="email"
                                        dir="ltr"
                                        required
                                        error={errors.email}
                                    />
                                    <SelectField
                                        label={t('org.invite.role')}
                                        name="roles[0][role_template_id]"
                                        defaultValue={
                                            roles[0] ? String(roles[0].id) : ''
                                        }
                                        options={roles.map((r) => ({
                                            value: String(r.id),
                                            label: r.name,
                                        }))}
                                        required
                                        error={
                                            errors[
                                                'roles.0.role_template_id'
                                            ] ?? errors.roles
                                        }
                                    />
                                    <SelectField
                                        label={t('org.invite.branches')}
                                        name="branch_ids[0]"
                                        defaultValue={
                                            branches[0]
                                                ? String(branches[0].id)
                                                : ''
                                        }
                                        options={branches.map((b) => ({
                                            value: String(b.id),
                                            label: b.name,
                                        }))}
                                        error={
                                            errors['branch_ids.0'] ??
                                            errors.branch_ids
                                        }
                                    />
                                    <div className="flex flex-wrap items-center justify-between gap-3 md:col-span-2">
                                        <p className="text-caption text-weak">
                                            {seats.limit === null
                                                ? t('org.setup.team_seats', {
                                                      used: seats.used,
                                                      limit: '—',
                                                  })
                                                : t('org.setup.team_seats', {
                                                      used: seats.used,
                                                      limit: seats.limit,
                                                  })}
                                        </p>
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            loading={processing}
                                            data-test="wizard-invite-send"
                                        >
                                            <Icon icon={Send} size="sm" />
                                            {t('org.invite.send')}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </StepFrame>
        </>
    );
}

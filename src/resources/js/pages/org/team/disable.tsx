import { Head, Link, useForm } from '@inertiajs/react';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { Check, Info } from 'lucide-react';
import type { FormEvent } from 'react';
import MemberController from '@/actions/App/Http/Controllers/Org/MemberController';
import { TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Props = {
    member: {
        id: number;
        name: string;
        email: string;
        roles: string[];
        branches: string[];
        is_owner: boolean;
        status: string;
    };
};

export default function MemberDisable({ member }: Props) {
    const t = useT();
    const form = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(MemberController.disable.url({ membership: member.id }));
    };

    return (
        <>
            <Head
                title={t('org.member.disable_title', { name: member.name })}
            />
            <PageHeader
                title={t('org.member.disable_title', { name: member.name })}
                description={[
                    member.roles.join('، '),
                    member.branches.join('، '),
                ]
                    .filter(Boolean)
                    .join(' — ')}
                back={
                    <Link
                        href={MemberController.show.url({
                            membership: member.id,
                        })}
                        className="text-label text-link no-underline hover:text-brand-hover"
                    >
                        {member.name}
                    </Link>
                }
            />
            {errors.transition && (
                <Alert variant="danger" className="mb-4">
                    <AlertBody>{errors.transition}</AlertBody>
                </Alert>
            )}
            <div className="grid max-w-3xl gap-4">
                <Card>
                    <h2 className="text-label text-strong">
                        {t('org.member.open_work')}
                    </h2>
                    <p className="flex items-start gap-2 text-body text-weak">
                        <Icon icon={Info} size="sm" className="mt-1" />
                        {t('org.member.open_work_none')}
                    </p>
                </Card>
                <Card>
                    <form
                        onSubmit={submit}
                        className="grid gap-4"
                        data-test="confirm-disable"
                    >
                        <TextareaField
                            label={t('org.member.reason')}
                            hint={t('org.member.reason_hint')}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            required
                            error={errors.reason}
                        />
                        <div>
                            <h2 className="mb-2 text-label text-strong">
                                {t('org.member.on_disable')}
                            </h2>
                            <ul className="m-0 flex list-none flex-col gap-2 p-0 text-body text-strong">
                                {(
                                    [
                                        'org.member.on_disable_1',
                                        'org.member.on_disable_2',
                                        'org.member.on_disable_3',
                                    ] as const
                                ).map((key, index) => (
                                    <li
                                        key={key}
                                        className="flex items-start gap-2"
                                    >
                                        <Icon
                                            icon={index < 2 ? Check : Info}
                                            size="sm"
                                            className="mt-1 text-weak"
                                        />
                                        {t(key)}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button asChild variant="ghost">
                                <Link
                                    href={MemberController.show.url({
                                        membership: member.id,
                                    })}
                                >
                                    {t('ui.cancel')}
                                </Link>
                            </Button>
                            <Button
                                variant="destructive"
                                loading={form.processing}
                                data-test="disable-confirm"
                            >
                                {t('org.member.disable_confirm')}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}

MemberDisable.layout = {
    breadcrumbs: [{ title: 'org.team.title', href: TeamController.index() }],
};

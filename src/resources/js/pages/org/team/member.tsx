import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import MemberController from '@/actions/App/Http/Controllers/Org/MemberController';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { formatDate } from '@/components/org/format';
import { RoleRows } from '@/components/org/role-rows';
import type { RoleRow } from '@/components/org/role-rows';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    member: {
        id: number;
        name: string;
        email: string;
        job_title: string | null;
        status: 'active' | 'disabled';
        is_owner: boolean;
        is_self: boolean;
        department: string | null;
        branches: string[];
        disabled_at: string | null;
        disabled_reason: string | null;
        roles: {
            role_template_id: number;
            scope: string;
            effective_scope: string;
        }[];
    };
    grantGroups: {
        group: string;
        items: {
            code: string;
            label: string;
            scope: string;
            targets: string[];
        }[];
    }[];
    overrides: {
        permission: string;
        effect: 'allow' | 'deny';
        scope: string | null;
    }[];
    roleOptions: { id: number; name: string }[];
    scopes: string[];
    can: { access: boolean; disable: boolean; reactivate: boolean };
};

export default function MemberShow({
    member,
    grantGroups,
    overrides,
    roleOptions,
    scopes,
    can,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const form = useForm<{ roles: RoleRow[] }>({
        roles: member.roles.map((r) => ({
            role_template_id: r.role_template_id,
            scope: r.scope,
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(MemberController.updateAccess.url({ membership: member.id }), {
            preserveScroll: true,
        });
    };

    const details: [string, string][] = [
        [t('org.member.email'), member.email],
        [t('org.member.job_title'), member.job_title ?? '—'],
        [
            t('org.member.department'),
            member.department ?? t('org.team.no_department'),
        ],
        [t('org.member.branches'), member.branches.join('، ') || '—'],
    ];

    return (
        <>
            <Head title={member.name} />
            <PageHeader
                title={member.name}
                description={member.job_title ?? undefined}
                back={
                    <Link
                        href={TeamController.index.url()}
                        className="text-label text-link no-underline hover:text-brand-hover"
                    >
                        {t('org.member.back')}
                    </Link>
                }
                actions={
                    <>
                        {can.reactivate && (
                            <Button
                                type="button"
                                variant="secondary"
                                loading={busy}
                                onClick={() =>
                                    router.post(
                                        MemberController.reactivate.url({
                                            membership: member.id,
                                        }),
                                        {},
                                        {
                                            onStart: () => setBusy(true),
                                            onFinish: () => setBusy(false),
                                        },
                                    )
                                }
                            >
                                <Icon icon={RotateCcw} />
                                {t('org.team.reactivate')}
                            </Button>
                        )}
                        {can.disable && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={MemberController.confirmDisable.url({
                                        membership: member.id,
                                    })}
                                >
                                    <Icon icon={Lock} />
                                    {t('org.team.disable')}
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />
            {errors.license && (
                <Alert variant="danger" className="mb-4" data-test="seat-limit">
                    <AlertBody>{errors.license}</AlertBody>
                </Alert>
            )}
            {errors.transition && (
                <Alert variant="danger" className="mb-4">
                    <AlertBody>{errors.transition}</AlertBody>
                </Alert>
            )}
            {member.status === 'disabled' && (
                <Alert variant="warning" className="mb-4">
                    <AlertBody>
                        {t('org.member.disabled_banner', {
                            date: member.disabled_at
                                ? formatDate(member.disabled_at, locale)
                                : '—',
                            reason: member.disabled_reason ?? '—',
                        })}
                    </AlertBody>
                </Alert>
            )}
            <div className="grid items-start gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
                <Card>
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="text-subtitle text-strong">
                            {t('org.member.title')}
                        </h2>
                        <Badge
                            variant={
                                member.status === 'active'
                                    ? 'success'
                                    : 'neutral'
                            }
                        >
                            {t(`org.status.${member.status}` as TranslationKey)}
                        </Badge>
                    </div>
                    <dl className="m-0 grid gap-3">
                        {details.map(([label, value]) => (
                            <div key={label} className="flex flex-col gap-0.5">
                                <dt className="text-caption text-weak">
                                    {label}
                                </dt>
                                <dd
                                    className="m-0 break-words text-strong"
                                    dir="auto"
                                >
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>
                <div className="flex min-w-0 flex-col gap-4">
                    <Card>
                        <h2 className="text-subtitle text-strong">
                            {t('org.member.access_title')}
                        </h2>
                        {member.is_owner ? (
                            <p className="text-body text-weak">
                                {t('org.member.owner_note')}
                            </p>
                        ) : (
                            <form
                                onSubmit={save}
                                className="grid gap-4"
                                data-test="access-form"
                            >
                                <p className="text-body text-weak">
                                    {t('org.member.access_desc')}
                                </p>
                                <RoleRows
                                    rows={form.data.roles}
                                    onChange={(rows) =>
                                        form.setData('roles', rows)
                                    }
                                    roleOptions={roleOptions}
                                    scopes={scopes}
                                    errors={errors}
                                    disabled={!can.access}
                                />
                                {can.access && (
                                    <div>
                                        <Button
                                            loading={form.processing}
                                            data-test="access-save"
                                        >
                                            {t('org.member.save_access')}
                                        </Button>
                                    </div>
                                )}
                            </form>
                        )}
                    </Card>
                    {overrides.length > 0 && (
                        <Card>
                            <h2 className="text-subtitle text-strong">
                                {t('org.member.overrides')}
                            </h2>
                            <ul className="m-0 flex list-none flex-col gap-2 p-0">
                                {overrides.map((override, index) => (
                                    <li
                                        key={index}
                                        className="flex flex-wrap items-center gap-2 text-body"
                                    >
                                        <Badge
                                            variant={
                                                override.effect === 'allow'
                                                    ? 'success'
                                                    : 'danger'
                                            }
                                        >
                                            {t(
                                                `org.member.effect_${override.effect}` as TranslationKey,
                                            )}
                                        </Badge>
                                        {override.permission}
                                        {override.scope && (
                                            <span className="text-caption text-weak">
                                                {t(
                                                    `org.scope.${override.scope}` as TranslationKey,
                                                )}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                    <Card>
                        <h2 className="text-subtitle text-strong">
                            {t('org.member.grants')}
                        </h2>
                        <p className="text-body text-weak">
                            {t('org.member.grants_desc')}
                        </p>
                        {grantGroups.length === 0 ? (
                            <p className="text-body text-weak">
                                {t('org.member.grants_none')}
                            </p>
                        ) : (
                            grantGroups.map((group) => (
                                <section
                                    key={group.group}
                                    className="flex flex-col gap-1"
                                    aria-label={t(
                                        `org.group.${group.group}` as TranslationKey,
                                    )}
                                >
                                    <h3 className="text-label text-strong">
                                        {t(
                                            `org.group.${group.group}` as TranslationKey,
                                        )}
                                    </h3>
                                    <ul className="m-0 flex list-none flex-col divide-y divide-stroke-weak p-0">
                                        {group.items.map((item) => (
                                            <li
                                                key={item.code}
                                                className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2 text-body"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    {item.label}
                                                </span>
                                                <span className="text-caption text-weak">
                                                    {t(
                                                        `org.scope.${item.scope}` as TranslationKey,
                                                    )}
                                                    {item.targets.length > 0 &&
                                                        ` — ${item.targets.join('، ')}`}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))
                        )}
                    </Card>
                </div>
            </div>
        </>
    );
}

MemberShow.layout = {
    breadcrumbs: [{ title: 'org.team.title', href: TeamController.index() }],
};

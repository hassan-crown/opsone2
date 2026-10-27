import { Head, Link, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import RoleController from '@/actions/App/Http/Controllers/Org/RoleController';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { OrgTabs } from '@/components/org/org-tabs';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Props = {
    roles: { id: number; name: string; is_owner: boolean; members: number }[];
    role: {
        id: number;
        name: string;
        is_owner: boolean;
        starter: boolean;
        holders: string[];
        grants: { permission: string; scope: string }[];
    };
    catalogue: {
        group: string;
        items: { code: string; label: string; scopes: string[] }[];
    }[];
    canEdit: boolean;
};

export default function Roles({ roles, role, catalogue, canEdit }: Props) {
    const t = useT();
    const [selected, setSelected] = useState<Record<string, string>>(() =>
        Object.fromEntries(role.grants.map((g) => [g.permission, g.scope])),
    );
    const form = useForm<{ grants: { permission: string; scope: string }[] }>({
        grants: [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const locked = role.is_owner || !canEdit;

    const toggle = (code: string, scopes: string[], checked: boolean) =>
        setSelected((current) => {
            const next = { ...current };
            if (checked) {
                next[code] = scopes[0];
            } else {
                delete next[code];
            }

            return next;
        });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform(() => ({
            grants: Object.entries(selected).map(([permission, scope]) => ({
                permission,
                scope,
            })),
        }));
        form.put(RoleController.update.url({ template: role.id }), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('org.roles.title', { name: role.name })} />
            <PageHeader
                title={t('org.roles.title', { name: role.name })}
                description={
                    role.holders.length > 0
                        ? t('org.roles.holders', {
                              names: role.holders.join('، '),
                          })
                        : t('org.roles.no_holders')
                }
                actions={
                    !locked && (
                        <Button
                            type="submit"
                            form="role-form"
                            loading={form.processing}
                            data-test="role-save"
                        >
                            {t('org.roles.save')}
                        </Button>
                    )
                }
            />
            <OrgTabs
                active="roles"
                counts={{
                    members: roles.reduce((n, r) => n + r.members, 0),
                    invitations: 0,
                    roles: roles.length,
                }}
                showCounts={false}
            />
            <div className="grid items-start gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <nav
                    aria-label={t('org.roles.list')}
                    className="flex flex-row gap-2 overflow-x-auto lg:flex-col"
                >
                    {roles.map((item) => (
                        <Link
                            key={item.id}
                            href={RoleController.edit.url({
                                template: item.id,
                            })}
                            aria-current={
                                item.id === role.id ? 'page' : undefined
                            }
                            className={cn(
                                'flex min-h-14 min-w-40 shrink-0 flex-col justify-center rounded-md border border-stroke-weak bg-surface px-4 py-2 no-underline hover:bg-hover lg:min-w-0',
                                item.id === role.id &&
                                    'border-brand bg-brand-subtle',
                            )}
                        >
                            <span className="flex items-center gap-2 font-semibold text-strong">
                                {item.name}
                                {item.is_owner && (
                                    <Icon
                                        icon={Lock}
                                        size="sm"
                                        className="text-weak"
                                    />
                                )}
                            </span>
                            <span className="text-caption text-weak">
                                {item.is_owner
                                    ? t('org.roles.locked') + '، '
                                    : ''}
                                {t('org.roles.member_count', {
                                    n: item.members,
                                })}
                            </span>
                        </Link>
                    ))}
                </nav>
                <form
                    id="role-form"
                    onSubmit={save}
                    className="flex min-w-0 flex-col gap-4"
                    data-test="role-form"
                >
                    {role.starter && !role.is_owner && (
                        <div>
                            <Badge variant="neutral">
                                {t('org.roles.starter')}
                            </Badge>
                        </div>
                    )}
                    <Alert variant="info">
                        <AlertBody>
                            <AlertTitle>
                                {t('org.roles.alert_title')}
                            </AlertTitle>
                            {t('org.roles.alert_body')}
                        </AlertBody>
                    </Alert>
                    {role.is_owner && (
                        <Alert variant="warning">
                            <AlertBody>{t('org.roles.owner_locked')}</AlertBody>
                        </Alert>
                    )}
                    {!role.is_owner && !canEdit && (
                        <Alert variant="warning">
                            <AlertBody>{t('org.roles.read_only')}</AlertBody>
                        </Alert>
                    )}
                    {errors.grants && (
                        <Alert variant="danger">
                            <AlertBody>{errors.grants}</AlertBody>
                        </Alert>
                    )}
                    {catalogue.map((group) => {
                        const count = group.items.filter(
                            (item) => role.is_owner || item.code in selected,
                        ).length;

                        return (
                            <Card key={group.group} className="gap-3">
                                <div className="flex items-baseline justify-between gap-2">
                                    <h2 className="text-subtitle text-strong">
                                        {t(
                                            `org.group.${group.group}` as TranslationKey,
                                        )}
                                    </h2>
                                    <span className="text-caption text-weak">
                                        {t('org.roles.group_count', {
                                            n: count,
                                            total: group.items.length,
                                        })}
                                    </span>
                                </div>
                                <ul className="m-0 flex list-none flex-col divide-y divide-stroke-weak p-0">
                                    {group.items.map((item) => {
                                        const checked =
                                            role.is_owner ||
                                            item.code in selected;
                                        const id = `perm-${item.code}`;

                                        return (
                                            <li
                                                key={item.code}
                                                className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1"
                                            >
                                                <div className="flex min-h-11 min-w-0 flex-1 items-center gap-3">
                                                    <input
                                                        type="checkbox"
                                                        id={id}
                                                        checked={checked}
                                                        disabled={locked}
                                                        onChange={(e) =>
                                                            toggle(
                                                                item.code,
                                                                item.scopes,
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                        className="size-5 shrink-0 cursor-pointer accent-brand disabled:cursor-not-allowed"
                                                    />
                                                    <label
                                                        htmlFor={id}
                                                        className="cursor-pointer text-control font-normal text-strong"
                                                    >
                                                        {item.label}
                                                    </label>
                                                </div>
                                                {checked && (
                                                    <select
                                                        aria-label={t(
                                                            'org.roles.scope_for',
                                                            {
                                                                permission:
                                                                    item.label,
                                                            },
                                                        )}
                                                        value={
                                                            role.is_owner
                                                                ? 'tenant'
                                                                : (selected[
                                                                      item.code
                                                                  ] ??
                                                                  item
                                                                      .scopes[0])
                                                        }
                                                        disabled={
                                                            locked ||
                                                            item.scopes.length <
                                                                2
                                                        }
                                                        onChange={(e) =>
                                                            setSelected(
                                                                (c) => ({
                                                                    ...c,
                                                                    [item.code]:
                                                                        e.target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                        className="field-control w-48 max-w-full cursor-pointer text-control"
                                                    >
                                                        {(role.is_owner
                                                            ? ['tenant']
                                                            : item.scopes
                                                        ).map((scope) => (
                                                            <option
                                                                key={scope}
                                                                value={scope}
                                                            >
                                                                {t(
                                                                    `org.scope.${scope}` as TranslationKey,
                                                                )}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </Card>
                        );
                    })}
                </form>
            </div>
        </>
    );
}

Roles.layout = {
    breadcrumbs: [{ title: 'org.team.title', href: TeamController.index() }],
};

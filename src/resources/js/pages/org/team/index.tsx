import { Head, Link, router } from '@inertiajs/react';
import {
    Lock,
    Pencil,
    Plus,
    RefreshCw,
    RotateCcw,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import MemberController from '@/actions/App/Http/Controllers/Org/MemberController';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Icon } from '@/components/opsone/icon';
import { SelectField, TextField } from '@/components/opsone/field';
import { formatDate } from '@/components/org/format';
import type { Seats } from '@/components/org/format';
import { OrgTabs } from '@/components/org/org-tabs';
import { RowMenu } from '@/components/org/row-menu';
import { SeatMeter } from '@/components/org/seat-meter';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    BlockedAction,
    useLicenseBlock,
} from '@/components/opsone/blocked-action';

type Member = {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    status: 'active' | 'disabled';
    is_owner: boolean;
    is_self: boolean;
    department: string | null;
    branches: string[];
    roles: { name: string; scope: string }[];
    can: { access: boolean; disable: boolean; reactivate: boolean };
};

type InvitationRow = {
    id: number;
    name: string | null;
    email: string;
    status: 'pending' | 'expired' | 'cancelled' | 'accepted';
    expires_at: string;
    roles: string[];
    branches: string[];
    invited_by: string | null;
    can: { renew: boolean; cancel: boolean };
};

type Props = {
    tab: 'members' | 'invitations';
    filters: { status: string | null; q: string; role: number | null };
    members?: Member[];
    invitations?: InvitationRow[];
    roles: { id: number; name: string }[];
    counts: { members: number; invitations: number; roles: number };
    seats: Seats;
    canInvite: boolean;
    canRoles: boolean;
};

const STATUS_VARIANT = {
    active: 'success',
    disabled: 'neutral',
    pending: 'warning',
    expired: 'danger',
    cancelled: 'neutral',
    accepted: 'success',
} as const;

function MemberFilters({ filters, roles }: Pick<Props, 'filters' | 'roles'>) {
    const t = useT();
    const [q, setQ] = useState(filters.q);

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            TeamController.index.url(),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit({ q });
    };

    const chips: { value: string | null; label: string }[] = [
        { value: null, label: t('org.team.filter_all') },
        { value: 'active', label: t('org.status.active') },
        { value: 'disabled', label: t('org.status.disabled') },
    ];

    return (
        <div
            className="mb-4 flex flex-col gap-3"
            role="search"
            aria-label={t('org.team.filters')}
        >
            <form
                onSubmit={submit}
                className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end"
            >
                <TextField
                    label={t('org.team.search')}
                    name="q"
                    value={q}
                    onChange={(event) => setQ(event.target.value)}
                    type="search"
                />
                <SelectField
                    label={t('org.team.filter_role')}
                    value={filters.role ? String(filters.role) : ''}
                    onChange={(event) =>
                        visit({
                            role: event.target.value
                                ? Number(event.target.value)
                                : null,
                        })
                    }
                    options={[
                        { value: '', label: t('org.team.filter_all_roles') },
                        ...roles.map((r) => ({
                            value: String(r.id),
                            label: r.name,
                        })),
                    ]}
                />
                <Button type="submit" variant="secondary">
                    {t('org.team.search_button')}
                </Button>
            </form>
            <div
                className="flex flex-wrap gap-2"
                role="group"
                aria-label={t('org.team.filter_status')}
            >
                {chips.map((chip) => {
                    const active = filters.status === chip.value;

                    return (
                        <button
                            key={chip.label}
                            type="button"
                            aria-pressed={active}
                            onClick={() => visit({ status: chip.value })}
                            className={cn(
                                'inline-flex min-h-9 items-center rounded-full border px-4 text-label',
                                active
                                    ? 'border-transparent bg-brand-subtle text-brand-subtle-text'
                                    : 'border-stroke-control text-weak hover:bg-hover hover:text-strong',
                            )}
                        >
                            {chip.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function MembersTable({ members }: { members: Member[] }) {
    const t = useT();

    if (members.length === 0) {
        return (
            <Card>
                <EmptyState
                    icon={Users}
                    title={t('org.team.empty_members')}
                    description={t('org.team.empty_members_desc')}
                />
            </Card>
        );
    }

    return (
        <Card className="gap-0 p-0 md:p-0">
            <TableWrap>
                <Table caption={t('org.team.caption_members')}>
                    <thead>
                        <tr>
                            <Th>{t('org.team.col_member')}</Th>
                            <Th>{t('org.team.col_roles')}</Th>
                            <Th>{t('org.team.col_department')}</Th>
                            <Th>{t('org.team.col_status')}</Th>
                            <Th className="text-end">
                                {t('org.team.col_actions')}
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((member) => (
                            <tr key={member.id} data-test="member-row">
                                <Td>
                                    <Link
                                        href={MemberController.show.url({
                                            membership: member.id,
                                        })}
                                        className="flex flex-col rounded-xs text-strong no-underline hover:text-brand-hover"
                                    >
                                        <span className="font-semibold">
                                            {member.name}
                                            {member.is_self && (
                                                <span className="ms-2 text-caption font-normal text-weak">
                                                    ({t('org.team.you')})
                                                </span>
                                            )}
                                        </span>
                                        <span
                                            className="text-caption text-weak"
                                            dir="ltr"
                                        >
                                            {member.email}
                                        </span>
                                    </Link>
                                </Td>
                                <Td>
                                    {member.roles.length === 0
                                        ? '—'
                                        : member.roles
                                              .map(
                                                  (role) =>
                                                      `${role.name}، ${t(`org.scope.${role.scope}` as TranslationKey)}`,
                                              )
                                              .join(' · ')}
                                </Td>
                                <Td>
                                    {member.department ??
                                        t('org.team.no_department')}
                                </Td>
                                <Td>
                                    <Badge
                                        variant={STATUS_VARIANT[member.status]}
                                    >
                                        {t(
                                            `org.status.${member.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </Td>
                                <Td className="text-end">
                                    <RowMenu
                                        label={t('org.team.row_actions')}
                                        items={[
                                            {
                                                label: t('org.team.view'),
                                                icon: UserRound,
                                                href: MemberController.show.url(
                                                    { membership: member.id },
                                                ),
                                            },
                                            ...(member.can.access
                                                ? [
                                                      {
                                                          label: t(
                                                              'org.team.edit_access',
                                                          ),
                                                          icon: Pencil,
                                                          href: MemberController.show.url(
                                                              {
                                                                  membership:
                                                                      member.id,
                                                              },
                                                          ),
                                                      },
                                                  ]
                                                : []),
                                            ...(member.can.reactivate
                                                ? [
                                                      {
                                                          label: t(
                                                              'org.team.reactivate',
                                                          ),
                                                          icon: RotateCcw,
                                                          onSelect: () =>
                                                              router.post(
                                                                  MemberController.reactivate.url(
                                                                      {
                                                                          membership:
                                                                              member.id,
                                                                      },
                                                                  ),
                                                              ),
                                                      },
                                                  ]
                                                : []),
                                            ...(member.can.disable
                                                ? [
                                                      {
                                                          label: t(
                                                              'org.team.disable',
                                                          ),
                                                          icon: Lock,
                                                          danger: true,
                                                          separatorBefore: true,
                                                          href: MemberController.confirmDisable.url(
                                                              {
                                                                  membership:
                                                                      member.id,
                                                              },
                                                          ),
                                                      },
                                                  ]
                                                : []),
                                        ]}
                                    />
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </TableWrap>
        </Card>
    );
}

function InvitationsTable({ invitations }: { invitations: InvitationRow[] }) {
    const t = useT();
    const { locale } = useLocale();

    if (invitations.length === 0) {
        return (
            <Card>
                <EmptyState
                    icon={Users}
                    title={t('org.team.empty_invitations')}
                    description={t('org.team.empty_invitations_desc')}
                />
            </Card>
        );
    }

    return (
        <Card className="gap-0 p-0 md:p-0">
            <TableWrap>
                <Table caption={t('org.team.caption_invitations')}>
                    <thead>
                        <tr>
                            <Th>{t('org.team.col_invitee')}</Th>
                            <Th>{t('org.team.col_roles')}</Th>
                            <Th>{t('org.team.col_branches')}</Th>
                            <Th>{t('org.team.col_status')}</Th>
                            <Th>{t('org.team.col_expires')}</Th>
                            <Th className="text-end">
                                {t('org.team.col_actions')}
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {invitations.map((invitation) => (
                            <tr key={invitation.id} data-test="invitation-row">
                                <Td>
                                    <span className="flex flex-col">
                                        <span className="font-semibold">
                                            {invitation.name ??
                                                invitation.email}
                                        </span>
                                        <span
                                            className="text-caption text-weak"
                                            dir="ltr"
                                        >
                                            {invitation.email}
                                        </span>
                                    </span>
                                </Td>
                                <Td>{invitation.roles.join('، ')}</Td>
                                <Td>{invitation.branches.join('، ') || '—'}</Td>
                                <Td>
                                    <Badge
                                        variant={
                                            STATUS_VARIANT[invitation.status]
                                        }
                                    >
                                        {t(
                                            `org.status.${invitation.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </Td>
                                <Td>
                                    {invitation.status === 'expired'
                                        ? t('org.team.expired_on', {
                                              date: formatDate(
                                                  invitation.expires_at,
                                                  locale,
                                              ),
                                          })
                                        : t('org.team.expires_on', {
                                              date: formatDate(
                                                  invitation.expires_at,
                                                  locale,
                                              ),
                                          })}
                                </Td>
                                <Td className="text-end">
                                    <RowMenu
                                        label={t('org.team.inv_actions')}
                                        items={[
                                            ...(invitation.can.renew
                                                ? [
                                                      {
                                                          label: t(
                                                              'org.team.renew',
                                                          ),
                                                          icon: RefreshCw,
                                                          onSelect: () =>
                                                              router.post(
                                                                  TeamController.renew.url(
                                                                      {
                                                                          invitation:
                                                                              invitation.id,
                                                                      },
                                                                  ),
                                                              ),
                                                      },
                                                  ]
                                                : []),
                                            ...(invitation.can.cancel
                                                ? [
                                                      {
                                                          label: t(
                                                              'org.team.cancel',
                                                          ),
                                                          icon: X,
                                                          danger: true,
                                                          separatorBefore: true,
                                                          onSelect: () =>
                                                              router.post(
                                                                  TeamController.cancel.url(
                                                                      {
                                                                          invitation:
                                                                              invitation.id,
                                                                      },
                                                                  ),
                                                              ),
                                                      },
                                                  ]
                                                : []),
                                        ]}
                                    />
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </TableWrap>
        </Card>
    );
}

export default function TeamIndex(props: Props) {
    const t = useT();
    const blocked = useLicenseBlock('team_invite');

    return (
        <>
            <Head title={t('org.team.title')} />
            <PageHeader
                title={t('org.team.title')}
                description={t('org.team.description')}
                actions={
                    blocked ? (
                        <BlockedAction
                            label={t('org.team.invite')}
                            reason={blocked}
                            testId="invite-button"
                        />
                    ) : (
                        props.canInvite && (
                            <Button asChild data-test="invite-button">
                                <Link href={TeamController.create.url()}>
                                    <Icon icon={Plus} />
                                    {t('org.team.invite')}
                                </Link>
                            </Button>
                        )
                    )
                }
            />
            <OrgTabs
                active={props.tab}
                counts={props.counts}
                showRoles={props.canRoles}
            />
            <SeatMeter seats={props.seats} />
            {props.tab === 'members' ? (
                <>
                    <MemberFilters
                        filters={props.filters}
                        roles={props.roles}
                    />
                    <MembersTable members={props.members ?? []} />
                </>
            ) : (
                <InvitationsTable invitations={props.invitations ?? []} />
            )}
        </>
    );
}

TeamIndex.layout = {
    breadcrumbs: [{ title: 'org.team.title', href: TeamController.index() }],
};

import { Link } from '@inertiajs/react';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import RoleController from '@/actions/App/Http/Controllers/Org/RoleController';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type OrgTab = 'members' | 'invitations' | 'roles';

/** Tab-style navigation between the members, invitations and roles pages (real links, not client tabs). */
export function OrgTabs({
    active,
    counts,
    showRoles = true,
    showCounts = true,
}: {
    active: OrgTab;
    counts: { members: number; invitations: number; roles: number };
    showRoles?: boolean;
    showCounts?: boolean;
}) {
    const t = useT();
    const tabs: { key: OrgTab; label: string; href: string; count: number }[] =
        [
            {
                key: 'members',
                label: t('org.team.tab_members'),
                href: TeamController.index.url(),
                count: counts.members,
            },
            {
                key: 'invitations',
                label: t('org.team.tab_invitations'),
                href: TeamController.invitations.url(),
                count: counts.invitations,
            },
            ...(showRoles
                ? [
                      {
                          key: 'roles' as const,
                          label: t('org.team.tab_roles'),
                          href: RoleController.index.url(),
                          count: counts.roles,
                      },
                  ]
                : []),
        ];

    return (
        <nav
            aria-label={t('org.team.tabs')}
            className="mb-4 flex [scrollbar-width:none] gap-1 overflow-x-auto border-b border-stroke-weak"
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={tab.key === active ? 'page' : undefined}
                    className={cn(
                        'relative inline-flex min-h-11 shrink-0 items-center gap-2 rounded-t-md px-4 text-control text-weak hover:bg-hover hover:text-strong focus-visible:-outline-offset-2',
                        tab.key === active &&
                            'font-semibold text-strong after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-xs after:bg-brand',
                    )}
                >
                    {tab.label}
                    {showCounts && (
                        <span className="rounded-full bg-neutral-bg px-2 text-caption text-neutral-text">
                            {tab.count}
                        </span>
                    )}
                </Link>
            ))}
        </nav>
    );
}

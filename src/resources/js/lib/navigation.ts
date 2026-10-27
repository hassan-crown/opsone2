import {
    GitBranch,
    LayoutGrid,
    Package,
    ScrollText,
    Users,
} from 'lucide-react';
import CatalogHomeController from '@/actions/App/Http/Controllers/Catalog/CatalogHomeController';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import BranchController from '@/actions/App/Http/Controllers/Org/BranchController';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { dashboard } from '@/routes';
import type { TranslationKey } from '@/lib/i18n';
import type { NavItem } from '@/types';

export type LocalizedNavItem = Omit<NavItem, 'title'> & {
    label: TranslationKey;
    /** Shown only when the server's Gate allows it (`orgNav` shared prop); routes still enforce access. */
    access?: 'team' | 'branches' | 'audit' | 'catalog';
};

/** Primary navigation (5–7 items max; settings live in the footer / user menu). */
export const mainNav: LocalizedNavItem[] = [
    { label: 'nav.dashboard', href: dashboard(), icon: LayoutGrid },
    {
        label: 'org.nav.team',
        href: TeamController.index(),
        icon: Users,
        access: 'team',
    },
    {
        label: 'catalog.nav',
        href: CatalogHomeController.url(),
        icon: Package,
        access: 'catalog',
    },
    {
        label: 'org.nav.branches',
        href: BranchController.index(),
        icon: GitBranch,
        access: 'branches',
    },
    {
        label: 'audit.nav',
        href: AuditLogController.index(),
        icon: ScrollText,
        access: 'audit',
    },
];

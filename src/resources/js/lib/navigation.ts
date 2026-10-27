import { LayoutGrid } from 'lucide-react';
import { dashboard } from '@/routes';
import type { TranslationKey } from '@/lib/i18n';
import type { NavItem } from '@/types';

export type LocalizedNavItem = Omit<NavItem, 'title'> & {
    label: TranslationKey;
};

/** Primary navigation (5–7 items max; settings live in the footer / user menu). */
export const mainNav: LocalizedNavItem[] = [
    { label: 'nav.dashboard', href: dashboard(), icon: LayoutGrid },
];

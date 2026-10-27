import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { TranslationKey } from '@/lib/i18n';

/** `title` is a translation key; layouts resolve it with t(). */
export type BreadcrumbItem = {
    title: TranslationKey;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

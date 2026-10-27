import type { ReactNode } from 'react';
import { AppShell } from '@/components/opsone/app-shell';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
}) {
    return <AppShell breadcrumbs={breadcrumbs}>{children}</AppShell>;
}

import {
    BadgePercent,
    GitBranch,
    ClipboardList,
    ListChecks,
    Receipt,
    CalendarDays,
    Inbox,
    LayoutGrid,
    Package,
    PackageCheck,
    Undo2,
    Truck,
    ScrollText,
    Workflow,
    Users,
    Warehouse,
} from 'lucide-react';
import CommissionController from '@/actions/App/Http/Controllers/Commissions/CommissionController';
import CalendarController from '@/actions/App/Http/Controllers/Calendar/CalendarController';
import CatalogHomeController from '@/actions/App/Http/Controllers/Catalog/CatalogHomeController';
import StockController from '@/actions/App/Http/Controllers/Inventory/StockController';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import FinanceHomeController from '@/actions/App/Http/Controllers/Finance/FinanceHomeController';
import FlowController from '@/actions/App/Http/Controllers/Flow/FlowController';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import DeliveryController from '@/actions/App/Http/Controllers/Delivery/DeliveryController';
import ReturnController from '@/actions/App/Http/Controllers/Returns/ReturnController';
import PurchaseController from '@/actions/App/Http/Controllers/Procurement/PurchaseController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import BranchController from '@/actions/App/Http/Controllers/Org/BranchController';
import TeamController from '@/actions/App/Http/Controllers/Org/TeamController';
import { dashboard } from '@/routes';
import type { TranslationKey } from '@/lib/i18n';
import type { NavItem } from '@/types';

export type LocalizedNavItem = Omit<NavItem, 'title'> & {
    label: TranslationKey;
    /** Shown only when the server's Gate allows it (`orgNav` shared prop); routes still enforce access. */
    access?:
        | 'team'
        | 'branches'
        | 'audit'
        | 'customers'
        | 'catalog'
        | 'orders'
        | 'flow'
        | 'tasks'
        | 'calendar'
        | 'finance'
        | 'commissions'
        | 'stock'
        | 'procurement'
        | 'delivery'
        | 'returns';
};

/** Primary navigation (5–7 items max; settings live in the footer / user menu). */
export const mainNav: LocalizedNavItem[] = [
    { label: 'nav.dashboard', href: dashboard(), icon: LayoutGrid },
    {
        label: 'orders.nav',
        href: OrderController.index(),
        icon: ClipboardList,
        access: 'orders',
    },
    {
        label: 'tasks.nav',
        href: TaskController.index(),
        icon: ListChecks,
        access: 'tasks',
    },
    {
        label: 'calendar.nav',
        href: CalendarController.index(),
        icon: CalendarDays,
        access: 'calendar',
    },
    {
        label: 'finance.nav',
        href: FinanceHomeController.index(),
        icon: Receipt,
        access: 'finance',
    },
    {
        label: 'commissions.nav',
        href: CommissionController.index(),
        icon: BadgePercent,
        access: 'commissions',
    },
    {
        label: 'procurement.nav',
        href: PurchaseController.index(),
        icon: Truck,
        access: 'procurement',
    },
    {
        label: 'delivery.nav',
        href: DeliveryController.index(),
        icon: PackageCheck,
        access: 'delivery',
    },
    {
        label: 'returns.nav',
        href: ReturnController.index(),
        icon: Undo2,
        access: 'returns',
    },
    {
        label: 'customers.nav',
        href: CustomerController.index(),
        icon: Users,
        access: 'customers',
    },
    {
        label: 'interests.nav',
        href: InterestController.index(),
        icon: Inbox,
        access: 'customers',
    },
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
        label: 'stock.nav',
        href: StockController.index(),
        icon: Warehouse,
        access: 'stock',
    },
    {
        label: 'org.nav.branches',
        href: BranchController.index(),
        icon: GitBranch,
        access: 'branches',
    },
    {
        label: 'flow.nav',
        href: FlowController.edit(),
        icon: Workflow,
        access: 'flow',
    },
    {
        label: 'audit.nav',
        href: AuditLogController.index(),
        icon: ScrollText,
        access: 'audit',
    },
];

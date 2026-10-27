import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import type { LucideIcon } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type MenuItem = {
    label: string;
    icon?: LucideIcon;
    onSelect?: () => void;
    href?: ComponentProps<typeof Link>['href'];
    danger?: boolean;
    disabled?: boolean;
    /** Draws a separator before this item (danger items normally follow one). */
    separatorBefore?: boolean;
};

export type MenuProps = {
    trigger: ReactNode;
    items: MenuItem[];
    align?: 'start' | 'end';
};

/** Action menu: opens at the end edge by default, destructive items after a separator. */
export function Menu({ trigger, items, align = 'end' }: MenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent align={align}>
                {items.map((item) => (
                    <div key={item.label}>
                        {item.separatorBefore && <DropdownMenuSeparator />}
                        <DropdownMenuItem
                            variant={item.danger ? 'destructive' : 'default'}
                            disabled={item.disabled}
                            onSelect={item.onSelect}
                            asChild={Boolean(item.href)}
                        >
                            {item.href ? (
                                <Link href={item.href}>
                                    {item.icon && <Icon icon={item.icon} />}
                                    {item.label}
                                </Link>
                            ) : (
                                <>
                                    {item.icon && <Icon icon={item.icon} />}
                                    {item.label}
                                </>
                            )}
                        </DropdownMenuItem>
                    </div>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

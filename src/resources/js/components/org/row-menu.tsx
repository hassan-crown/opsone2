import { MoreHorizontal } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Menu } from '@/components/opsone/menu';
import type { MenuItem } from '@/components/opsone/menu';
import { Button } from '@/components/ui/button';

export function RowMenu({
    label,
    items,
}: {
    label: string;
    items: MenuItem[];
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <Menu
            items={items}
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={label}
                >
                    <Icon icon={MoreHorizontal} />
                </Button>
            }
        />
    );
}

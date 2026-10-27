import { Link, router } from '@inertiajs/react';
import { LogOut, Settings } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useT } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const t = useT();
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-3 px-3 py-2 text-start">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link href={edit()} prefetch onClick={cleanup}>
                        <Icon icon={Settings} />
                        {t('nav.settings')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild variant="destructive">
                <Link
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                    className="w-full"
                >
                    <Icon icon={LogOut} />
                    {t('nav.logout')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}

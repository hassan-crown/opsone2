import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
    hideTextOnMobile = false,
}: {
    user: User;
    showEmail?: boolean;
    /** Avatar only below `md` (topbar). */
    hideTextOnMobile?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className="size-10">
                <AvatarImage src={user.avatar} alt="" />
                <AvatarFallback>{getInitials(user.name)}</AvatarFallback>
            </Avatar>
            <div
                className={`grid min-w-0 flex-1 text-start leading-tight ${hideTextOnMobile ? 'max-md:hidden' : ''}`}
            >
                <span className="truncate text-label text-strong">
                    {user.name}
                </span>
                {showEmail && (
                    <span
                        dir="ltr"
                        className="truncate text-start text-caption text-weak"
                    >
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}

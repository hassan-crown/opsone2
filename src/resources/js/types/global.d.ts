import type { Auth } from '@/types/auth';
import type { LicenseBanner } from '@/types/license';
import type { NotificationFeed } from '@/types/notifications';
import type { Direction, Locale } from '@/lib/i18n';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: Locale;
            dir: Direction;
            locales: Locale[];
            translations: Record<string, string>;
            license?: LicenseBanner | null;
            notifications?: NotificationFeed | null;
            orgNav?: {
                team: boolean;
                branches: boolean;
                audit: boolean;
                customers: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}

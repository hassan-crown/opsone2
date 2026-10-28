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
                catalog: boolean;
                flow: boolean;
                orders: boolean;
                tasks: boolean;
                calendar: boolean;
                finance: boolean;
                commissions: boolean;
                stock: boolean;
                procurement: boolean;
                delivery: boolean;
                returns: boolean;
                site: boolean;
                store: boolean;
                tickets: boolean;
                /** P-60/P-61/P-62: needs the `reports` key in HandleInertiaRequests::orgNav(). */
                reports?: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}

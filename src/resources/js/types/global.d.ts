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
            /** ADR-0040: on business-rules pages, the draft against the release in force. */
            configDraft?: { pending: boolean; release: number | null } | null;
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
                /** ADR-0039: P-54, the customer appointment request queue. */
                appointmentRequests?: boolean;
                /** ADR-0041: P-105 with field sales on — the dispatch board. */
                fieldSales?: boolean;
                /** ADR-0041: P-105 — the supervisor's field team follow-up. */
                fieldTeam?: boolean;
                /** ADR-0041: a field sales rep — the field app link. */
                fieldApp?: boolean;
                finance: boolean;
                commissions: boolean;
                stock: boolean;
                procurement: boolean;
                delivery: boolean;
                returns: boolean;
                site: boolean;
                store: boolean;
                tickets: boolean;
                /** AI-3: P-108 and the WhatsApp inbox switched on. */
                inbox?: boolean;
                /** P-60/P-61/P-62: needs the `reports` key in HandleInertiaRequests::orgNav(). */
                reports?: boolean;
                /** P-60 ∧ P-37 (owner / managers): the owner dashboard. */
                executive?: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}

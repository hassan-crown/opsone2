import type { Locale } from '@/lib/i18n';

export function formatDate(iso: string, locale: Locale): string {
    return new Intl.DateTimeFormat(
        locale === 'ar' ? 'ar-u-ca-gregory-nu-latn' : 'en-GB',
        {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        },
    ).format(new Date(iso));
}

export type Seats = { used: number; limit: number | null };

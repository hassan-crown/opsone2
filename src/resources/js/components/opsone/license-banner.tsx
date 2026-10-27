import { usePage } from '@inertiajs/react';
import { CircleAlert, Clock, Info, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import type { TranslationKey } from '@/lib/i18n';
import { useT } from '@/lib/i18n';
import type { LicenseStatus } from '@/types';

type Variant = 'info' | 'warning' | 'danger';

const BANNERS: Record<
    LicenseStatus,
    {
        variant: Variant;
        icon: LucideIcon;
        title: TranslationKey;
        body: TranslationKey;
    }
> = {
    active: {
        variant: 'info',
        icon: Clock,
        title: 'license.renewal_title',
        body: 'license.renewal_body',
    },
    grace: {
        variant: 'warning',
        icon: TriangleAlert,
        title: 'license.grace_title',
        body: 'license.grace_body',
    },
    wind_down: {
        variant: 'warning',
        icon: TriangleAlert,
        title: 'license.wind_down_title',
        body: 'license.wind_down_body',
    },
    expired: {
        variant: 'danger',
        icon: CircleAlert,
        title: 'license.expired_title',
        body: 'license.expired_body',
    },
    provisioned: {
        variant: 'info',
        icon: Info,
        title: 'license.provisioned_title',
        body: 'license.provisioned_body',
    },
    needs_attention: {
        variant: 'danger',
        icon: CircleAlert,
        title: 'license.needs_attention_title',
        body: 'license.needs_attention_body',
    },
};

/** License state notice for the app shell (M-19). Informs only; access is enforced on the server. */
export function LicenseBanner() {
    const { license } = usePage().props;
    const t = useT();

    if (!license?.show) {
        return null;
    }

    const banner = BANNERS[license.status];

    return (
        <Alert variant={banner.variant} className="mb-4 md:mb-6">
            <Icon icon={banner.icon} />
            <AlertBody>
                <AlertTitle>{t(banner.title)}</AlertTitle>
                <AlertDescription>
                    <p className="m-0">
                        {t(banner.body, { days: license.days_remaining ?? 0 })}
                    </p>
                    {license.problem && (
                        <p className="m-0 text-weak">
                            {t('license.reference', { code: license.problem })}
                        </p>
                    )}
                </AlertDescription>
            </AlertBody>
        </Alert>
    );
}

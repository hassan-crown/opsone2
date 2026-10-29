import { usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useId } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import type { TranslationKey } from '@/lib/i18n';
import { useT } from '@/lib/i18n';
import type { LicenseBlockReason, LicenseBlockedAction } from '@/types';

/** The reason a main add action is unavailable under the current license phase or plan limits, if any. */
export function useLicenseBlock(
    action: LicenseBlockedAction,
): LicenseBlockReason | null {
    const { license } = usePage().props;

    return license?.blocked?.[action] ?? null;
}

/**
 * A main action shown disabled with its reason (P9 license states). Informs only: the server refuses anyway.
 */
export function BlockedAction({
    label,
    reason,
    testId,
}: {
    label: string;
    reason: LicenseBlockReason;
    testId?: string;
}) {
    const t = useT();
    const id = useId();

    return (
        <div className="flex max-w-[40ch] flex-col items-end gap-1 text-end">
            <Button
                type="button"
                disabled
                aria-describedby={id}
                data-test={testId}
                data-blocked={reason}
            >
                <Icon icon={Lock} />
                {label}
            </Button>
            <p id={id} className="m-0 text-caption text-weak">
                {t(`license.blocked.${reason}` as TranslationKey)}
            </p>
        </div>
    );
}

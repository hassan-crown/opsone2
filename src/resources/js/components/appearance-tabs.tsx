import { Monitor, Moon, Sun } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import { Icon } from '@/components/opsone/icon';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const TABS: { value: Appearance; icon: LucideIcon; label: TranslationKey }[] = [
    { value: 'light', icon: Sun, label: 'appearance.light' },
    { value: 'dark', icon: Moon, label: 'appearance.dark' },
    { value: 'system', icon: Monitor, label: 'appearance.system' },
];

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const t = useT();
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="radiogroup"
            aria-label={t('appearance.title')}
            className={cn(
                'inline-flex gap-1 rounded-md border border-stroke-weak bg-fill p-1',
                className,
            )}
            {...props}
        >
            {TABS.map(({ value, icon, label }) => (
                <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'flex min-h-9 items-center gap-2 rounded-sm px-3 text-label transition-colors',
                        appearance === value
                            ? 'bg-surface font-semibold text-strong shadow-[inset_0_0_0_1px_var(--stroke-control)]'
                            : 'text-weak hover:bg-hover hover:text-strong',
                    )}
                >
                    <Icon icon={icon} size="sm" />
                    {t(label)}
                </button>
            ))}
        </div>
    );
}

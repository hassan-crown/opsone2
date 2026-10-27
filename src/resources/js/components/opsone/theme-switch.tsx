import { Monitor, Moon, Sun } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

// "contrast" tokens exist but are not exposed as a toggle yet.
const MODES: { value: Appearance; icon: LucideIcon; label: TranslationKey }[] =
    [
        { value: 'light', icon: Sun, label: 'appearance.light' },
        { value: 'dark', icon: Moon, label: 'appearance.dark' },
        { value: 'system', icon: Monitor, label: 'appearance.system' },
    ];

export function ThemeSwitch() {
    const t = useT();
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();
    const current =
        MODES.find((mode) => mode.value === resolvedAppearance) ?? MODES[0];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={t('appearance.title')}
                    className="text-strong"
                >
                    <Icon icon={current.icon} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={appearance}
                    onValueChange={(value) =>
                        updateAppearance(value as Appearance)
                    }
                >
                    {MODES.map((mode) => (
                        <DropdownMenuRadioItem
                            key={mode.value}
                            value={mode.value}
                        >
                            <Icon icon={mode.icon} size="sm" />
                            {t(mode.label)}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

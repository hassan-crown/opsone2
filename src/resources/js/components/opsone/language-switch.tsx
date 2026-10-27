import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useLocale, useT } from '@/lib/i18n';
import type { Locale } from '@/lib/i18n';
import { update } from '@/routes/locale';

const NAMES: Record<Locale, string> = { ar: 'العربية', en: 'English' };

/** Switches language with a full Inertia visit so lang/dir/fonts/translations all reload together. */
export function LanguageSwitch() {
    const t = useT();
    const { locale, locales } = useLocale();
    const target = locales.find((candidate) => candidate !== locale) ?? locale;

    return (
        <Button
            type="button"
            variant="ghost"
            size="default"
            lang={target}
            aria-label={t('ui.switch_language', { language: NAMES[target] })}
            onClick={() =>
                router.put(
                    update().url,
                    { locale: target },
                    { preserveScroll: true },
                )
            }
            className="px-3 text-strong"
        >
            <Icon icon={Languages} />
            <span className="max-sm:sr-only">{NAMES[target]}</span>
        </Button>
    );
}

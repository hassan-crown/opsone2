import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { RouteDefinition } from '@/wayfinder';
import {
    clauses,
    messages,
    numbering,
    plans,
    reminders,
    sla,
} from '@/routes/rules';

export type RulesPage =
    | 'plans'
    | 'reminders'
    | 'clauses'
    | 'messages'
    | 'sla'
    | 'numbering';

export const RULES_PAGES: {
    key: RulesPage;
    href: () => RouteDefinition<'get'>;
}[] = [
    { key: 'plans', href: plans },
    { key: 'reminders', href: reminders },
    { key: 'clauses', href: clauses },
    { key: 'messages', href: messages },
    { key: 'sla', href: sla },
    { key: 'numbering', href: numbering },
];

/** The business-rules pages (ADR-0037) as tabs, plus the view-only and audit notes. */
export function RulesNav({
    current,
    canEdit,
}: {
    current: RulesPage;
    canEdit: boolean;
}) {
    const t = useT();

    return (
        <div className="mb-6 space-y-4">
            <nav aria-label={t('rules.section')}>
                <ul className="flex flex-wrap gap-2">
                    {RULES_PAGES.map((page) => (
                        <li key={page.key}>
                            <Link
                                href={page.href()}
                                aria-current={
                                    page.key === current ? 'page' : undefined
                                }
                                className={cn(
                                    'inline-flex min-h-9 items-center rounded-md border px-3 text-label no-underline',
                                    page.key === current
                                        ? 'border-brand bg-brand-subtle text-brand-subtle-text'
                                        : 'border-stroke-weak text-strong hover:bg-hover',
                                )}
                            >
                                {t(`rules.nav.${page.key}` as TranslationKey)}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
            <Alert variant="info" className="max-w-3xl">
                <AlertBody>
                    <AlertDescription>
                        {canEdit ? t('rules.audited') : t('rules.view_only')}
                    </AlertDescription>
                </AlertBody>
            </Alert>
        </div>
    );
}

type Method = 'post' | 'put' | 'delete';

/** An Inertia write with its field errors and busy state (the server redirects back with a toast). */
export function useRulesWrite() {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const send = (
        method: Method,
        url: string,
        data: Record<string, unknown>,
        onSuccess?: () => void,
    ) => {
        setBusy(true);
        router.visit(url, {
            method,
            data: data as never,
            preserveScroll: true,
            preserveState: true,
            onError: (e) => setErrors(e as Record<string, string>),
            onSuccess: () => {
                setErrors({});
                onSuccess?.();
            },
            onFinish: () => setBusy(false),
        });
    };

    return { errors, busy, send, setErrors };
}

/** The first error of a field or of anything under it (`lines` shows `lines.0.value` too). */
export function errorOf(
    errors: Record<string, string>,
    field: string,
): string | undefined {
    return (
        errors[field] ??
        Object.entries(errors).find(([k]) => k.startsWith(`${field}.`))?.[1]
    );
}

import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppointmentRequestSettingsController from '@/actions/App/Http/Controllers/AppointmentRequests/AppointmentRequestSettingsController';
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
    releases,
    reminders,
    sla,
} from '@/routes/rules';

export type RulesPage =
    | 'plans'
    | 'reminders'
    | 'clauses'
    | 'messages'
    | 'sla'
    | 'numbering'
    | 'appointment_requests'
    | 'releases';

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
    {
        key: 'appointment_requests',
        href: () => AppointmentRequestSettingsController.edit(),
    },
    { key: 'releases', href: releases },
];

/** Settings that apply when saved: not part of a configuration release (ADR-0040). */
const LIVE_PAGES: RulesPage[] = ['appointment_requests'];

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
                        {!canEdit
                            ? t('rules.view_only')
                            : LIVE_PAGES.includes(current)
                              ? t('rules.audited_live')
                              : t('rules.audited')}
                    </AlertDescription>
                </AlertBody>
            </Alert>
            {current !== 'releases' && !LIVE_PAGES.includes(current) && (
                <DraftBanner />
            )}
        </div>
    );
}

/**
 * ADR-0040: changes on these pages are a draft until published as a configuration release. Shown on every rules page
 * (and the purchase-order document settings) while the draft differs from the release in force.
 */
export function DraftBanner() {
    const t = useT();
    const draft = usePage().props.configDraft;
    if (!draft) {
        return null;
    }

    return (
        <Alert
            variant={draft.pending ? 'warning' : 'success'}
            className="max-w-3xl"
            data-test="config-draft-banner"
        >
            <AlertBody>
                <AlertDescription>
                    {draft.pending
                        ? t('rules.releases.banner_pending')
                        : t('rules.releases.banner_live', {
                              number: draft.release ?? 0,
                          })}{' '}
                    <Link href={releases()} className="text-link underline">
                        {t('rules.releases.banner_link')}
                    </Link>
                </AlertDescription>
            </AlertBody>
        </Alert>
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

import { Form, Head, router } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import { useState } from 'react';
import SupportAccessController from '@/actions/App/Http/Controllers/SupportAccess/SupportAccessController';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    CheckboxField,
    FieldError,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import type { TranslationKey } from '@/lib/i18n';
import { useLocale, useT } from '@/lib/i18n';
import { home } from '@/routes/settings';
import { index } from '@/routes/support-access';

type Status = 'requested' | 'active' | 'expired' | 'revoked' | 'rejected';

type GrantRow = {
    id: string;
    ticket: string;
    support_name: string;
    reason: string;
    status: Status;
    requested_areas: string[];
    requested_hours: number;
    areas: string[] | null;
    hours: number | null;
    request_expires_at: string;
    starts_at: string | null;
    expires_at: string | null;
    ended_at: string | null;
    decided_by: string | null;
};

type Activity = {
    id: number;
    action: string;
    actor: string | null;
    ticket: string | null;
    path: string | null;
    at: string;
};

type Props = {
    requested: GrantRow[];
    active: GrantRow[];
    history: GrantRow[];
    activity: Activity[];
    areas: string[];
    maxHours: number;
};

const STATUS_VARIANT: Record<
    Status,
    'success' | 'warning' | 'neutral' | 'danger'
> = {
    requested: 'warning',
    active: 'success',
    expired: 'neutral',
    revoked: 'neutral',
    rejected: 'danger',
};

function useAreaLabels() {
    const t = useT();

    return (areas: string[]) =>
        areas
            .map((area) => t(`support_access.area.${area}` as TranslationKey))
            .join('، ');
}

function hoursLeft(expiresAt: string): number {
    return Math.max(
        0,
        Math.ceil((new Date(expiresAt).getTime() - Date.now()) / 3_600_000),
    );
}

function ActiveGrant({ grant }: { grant: GrantRow }) {
    const t = useT();
    const { locale } = useLocale();
    const areaLabels = useAreaLabels();
    const [busy, setBusy] = useState(false);

    return (
        <article
            className="space-y-3 rounded-md border border-stroke-weak bg-surface p-4"
            data-test="support-grant-active"
        >
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="success">
                    {t('support_access.status.active')}
                </Badge>
                <h3 className="text-subtitle text-strong">
                    {grant.ticket}، {grant.reason}
                </h3>
            </div>
            <p className="text-body text-weak">
                {t('support_access.active_summary', {
                    areas: areaLabels(grant.areas ?? []),
                    hours: hoursLeft(grant.expires_at ?? ''),
                    end: grant.expires_at
                        ? formatDateTime(grant.expires_at, locale)
                        : '',
                })}
            </p>
            <p className="text-caption text-weak">
                {t('support_access.support_member', {
                    name: grant.support_name,
                })}
            </p>
            <Button
                type="button"
                variant="destructive"
                loading={busy}
                onClick={() => {
                    if (!window.confirm(t('support_access.revoke_confirm'))) {
                        return;
                    }
                    setBusy(true);
                    router.post(
                        SupportAccessController.revoke.url(grant.id),
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
                data-test="support-revoke"
            >
                {busy && <Spinner />}
                {t('support_access.revoke')}
            </Button>
        </article>
    );
}

function RequestedGrant({
    grant,
    maxHours,
}: {
    grant: GrantRow;
    maxHours: number;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [rejecting, setRejecting] = useState(false);
    const limit = Math.min(grant.requested_hours, maxHours);
    const hourOptions = [1, 4, 8, 24, 48, 72]
        .filter((h) => h < limit)
        .concat(limit)
        .map((h) => ({
            value: String(h),
            label: t('support_access.hours_option', { hours: h }),
        }));

    return (
        <article
            className="space-y-4 rounded-md border border-stroke-weak bg-surface p-4"
            data-test="support-grant-requested"
        >
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="warning">
                    {t('support_access.status.requested')}
                </Badge>
                <h3 className="text-subtitle text-strong">
                    {grant.ticket}، {grant.reason}
                </h3>
            </div>
            <p className="text-body text-weak">
                {t('support_access.requested_summary', {
                    name: grant.support_name,
                    hours: grant.requested_hours,
                    until: formatDateTime(grant.request_expires_at, locale),
                })}
            </p>

            <Form
                {...SupportAccessController.grant.form(grant.id)}
                options={{ preserveScroll: true }}
                resetOnSuccess={['current_password']}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <fieldset className="space-y-1">
                            <legend className="text-label text-strong">
                                {t('support_access.scope')}
                            </legend>
                            {grant.requested_areas.map((area) => (
                                <CheckboxField
                                    key={area}
                                    name="areas[]"
                                    value={area}
                                    defaultChecked
                                    label={t(
                                        `support_access.area.${area}` as TranslationKey,
                                    )}
                                />
                            ))}
                            <FieldError>{errors.areas}</FieldError>
                        </fieldset>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SelectField
                                label={t('support_access.duration')}
                                name="hours"
                                defaultValue={String(limit)}
                                required
                                options={hourOptions}
                                error={errors.hours}
                            />
                            <TextField
                                label={t('support_access.current_password')}
                                name="current_password"
                                type="password"
                                autoComplete="current-password"
                                required
                                error={errors.current_password}
                            />
                        </div>
                        <FieldError>{errors.transition}</FieldError>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                loading={processing}
                                data-test="support-grant"
                            >
                                {processing && <Spinner />}
                                {t('support_access.grant')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                loading={rejecting}
                                onClick={() => {
                                    setRejecting(true);
                                    router.post(
                                        SupportAccessController.reject.url(
                                            grant.id,
                                        ),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setRejecting(false),
                                        },
                                    );
                                }}
                                data-test="support-reject"
                            >
                                {rejecting && <Spinner />}
                                {t('support_access.reject')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </article>
    );
}

export default function SupportAccess({
    requested,
    active,
    history,
    activity,
    maxHours,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const areaLabels = useAreaLabels();
    const empty = requested.length === 0 && active.length === 0;

    return (
        <>
            <Head title={t('support_access.title')} />
            <PageHeader
                title={t('support_access.title')}
                description={t('support_access.description')}
            />

            <div className="grid max-w-5xl gap-8 lg:grid-cols-[1fr_18rem]">
                <div className="space-y-4">
                    {empty && (
                        <EmptyState
                            icon={LifeBuoy}
                            title={t('support_access.empty')}
                            description={t('support_access.empty_hint')}
                        />
                    )}
                    {active.map((grant) => (
                        <ActiveGrant key={grant.id} grant={grant} />
                    ))}
                    {requested.map((grant) => (
                        <RequestedGrant
                            key={grant.id}
                            grant={grant}
                            maxHours={maxHours}
                        />
                    ))}
                </div>

                <Alert variant="info" className="h-fit">
                    <AlertBody>
                        <AlertDescription>
                            <strong className="block text-strong">
                                {t('support_access.on_revoke')}
                            </strong>
                            {t('support_access.on_revoke_body')}
                            <span className="mt-2 block">
                                {t('support_access.no_extension')}
                            </span>
                        </AlertDescription>
                    </AlertBody>
                </Alert>
            </div>

            <section className="mt-10 max-w-5xl space-y-3">
                <h2 className="text-section text-strong">
                    {t('support_access.log')}
                </h2>
                {activity.length === 0 ? (
                    <p className="text-body text-weak">
                        {t('support_access.log_empty')}
                    </p>
                ) : (
                    <ul
                        className="divide-y divide-stroke-weak rounded-md border border-stroke-weak"
                        data-test="support-activity"
                    >
                        {activity.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-3 text-body"
                            >
                                <span className="text-weak tabular-nums">
                                    {formatDateTime(row.at, locale)}
                                </span>
                                <span className="text-strong">
                                    {t(
                                        `audit.action.${row.action}` as TranslationKey,
                                    )}
                                </span>
                                {row.path && (
                                    <span className="text-weak" dir="ltr">
                                        {row.path}
                                    </span>
                                )}
                                <span className="ms-auto text-caption text-weak">
                                    {row.ticket}
                                    {row.actor ? ` · ${row.actor}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {history.length > 0 && (
                <section className="mt-10 max-w-5xl space-y-3">
                    <h2 className="text-section text-strong">
                        {t('support_access.history')}
                    </h2>
                    <ul className="divide-y divide-stroke-weak rounded-md border border-stroke-weak">
                        {history.map((grant) => (
                            <li
                                key={grant.id}
                                className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-body"
                            >
                                <Badge variant={STATUS_VARIANT[grant.status]}>
                                    {t(
                                        `support_access.status.${grant.status}` as TranslationKey,
                                    )}
                                </Badge>
                                <span className="text-strong">
                                    {grant.ticket}، {grant.reason}
                                </span>
                                <span className="text-weak">
                                    {areaLabels(
                                        grant.areas ?? grant.requested_areas,
                                    )}
                                </span>
                                {grant.ended_at && (
                                    <span className="ms-auto text-caption text-weak">
                                        {formatDateTime(grant.ended_at, locale)}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}

SupportAccess.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'support_access.title', href: index() },
    ],
};

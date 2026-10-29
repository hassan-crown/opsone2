import { Head, router, usePoll } from '@inertiajs/react';
import { CircleCheck, Download, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import ClosureController from '@/actions/App/Http/Controllers/Account/ClosureController';
import { Amount } from '@/components/opsone/amount';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { TranslationKey } from '@/lib/i18n';
import { useLocale, useT } from '@/lib/i18n';
import { show } from '@/routes/closure';
import { home } from '@/routes/settings';
import type { LicenseStatus } from '@/types';

type Item = {
    key: string;
    count: number;
    amount_minor: number | null;
    bounded: boolean;
};

type ExportRow = {
    uuid: string;
    status: 'queued' | 'running' | 'ready' | 'failed' | 'expired';
    created_at: string;
    expires_at: string | null;
    size_bytes: number | null;
    summary: { tables: number; rows: number; files: number } | null;
    download_url: string | null;
};

type Props = {
    items: Item[];
    ready: boolean;
    subscription: { status: LicenseStatus; retention_days: number | null };
    exports: ExportRow[];
    busy: boolean;
};

const EXPORT_BADGE: Record<
    ExportRow['status'],
    'info' | 'success' | 'danger' | 'neutral'
> = {
    queued: 'info',
    running: 'info',
    ready: 'success',
    failed: 'danger',
    expired: 'neutral',
};

/** Facility closure readiness and full data export (Q-53 a). Owner only; the server re-checks every action. */
export default function Closure({
    items,
    ready,
    subscription,
    exports,
    busy,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [sending, setSending] = useState(false);

    usePoll(5000, { only: ['exports', 'busy'] }, { autoStart: busy });

    const requestExport = () => {
        setSending(true);
        router.post(
            ClosureController.store.url(),
            {},
            { preserveScroll: true, onFinish: () => setSending(false) },
        );
    };

    return (
        <>
            <Head title={t('closure.title')} />

            <PageHeader
                title={t('closure.title')}
                description={t('closure.description')}
            />

            <div className="grid max-w-5xl gap-6">
                <Alert variant={ready ? 'success' : 'warning'}>
                    <Icon icon={ready ? CircleCheck : TriangleAlert} />
                    <AlertBody>
                        <AlertTitle>
                            {ready
                                ? t('closure.ready_title')
                                : t('closure.not_ready_title')}
                        </AlertTitle>
                        <AlertDescription>
                            {ready
                                ? t('closure.ready_body')
                                : t('closure.not_ready_body')}
                        </AlertDescription>
                    </AlertBody>
                </Alert>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('closure.readiness')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul
                            className="divide-y divide-stroke-weak"
                            data-test="closure-items"
                        >
                            {items.map((item) => (
                                <li
                                    key={item.key}
                                    className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                    data-test={`closure-${item.key}`}
                                >
                                    <span className="text-body text-strong">
                                        {t(
                                            `closure.item.${item.key}` as TranslationKey,
                                        )}
                                    </span>
                                    <span className="flex items-center gap-3">
                                        {item.amount_minor !== null &&
                                            item.amount_minor > 0 && (
                                                <Amount
                                                    value={
                                                        item.amount_minor / 100
                                                    }
                                                />
                                            )}
                                        <Badge
                                            variant={
                                                item.count === 0
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {formatNumber(item.count)}
                                            {item.bounded ? '+' : ''}
                                        </Badge>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 text-caption text-weak">
                            {t('closure.no_deletion')}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('closure.export.title')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="max-w-reading text-body text-weak">
                            {t('closure.export.body', {
                                days: subscription.retention_days ?? 0,
                            })}
                        </p>
                        <Button
                            type="button"
                            onClick={requestExport}
                            disabled={busy || sending}
                            data-test="closure-export"
                        >
                            {(busy || sending) && <Spinner />}
                            {busy
                                ? t('closure.export.building')
                                : t('closure.export.request')}
                        </Button>

                        {exports.length > 0 && (
                            <ul className="divide-y divide-stroke-weak">
                                {exports.map((row) => (
                                    <li
                                        key={row.uuid}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <span className="space-y-1">
                                            <span className="block text-body text-strong">
                                                {formatDateTime(
                                                    row.created_at,
                                                    locale,
                                                )}
                                            </span>
                                            {row.summary && (
                                                <span className="block text-caption text-weak">
                                                    {t(
                                                        'closure.export.summary',
                                                        {
                                                            tables: formatNumber(
                                                                row.summary
                                                                    .tables,
                                                            ),
                                                            rows: formatNumber(
                                                                row.summary
                                                                    .rows,
                                                            ),
                                                            files: formatNumber(
                                                                row.summary
                                                                    .files,
                                                            ),
                                                        },
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <Badge
                                                variant={
                                                    EXPORT_BADGE[row.status]
                                                }
                                            >
                                                {t(
                                                    `closure.export.status.${row.status}` as TranslationKey,
                                                )}
                                            </Badge>
                                            {row.download_url && (
                                                <Button
                                                    asChild
                                                    variant="secondary"
                                                    size="sm"
                                                >
                                                    <a href={row.download_url}>
                                                        <Icon
                                                            icon={Download}
                                                            size="sm"
                                                        />
                                                        {t(
                                                            'closure.export.download',
                                                        )}
                                                    </a>
                                                </Button>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-caption text-weak">
                            {t('closure.export.link_note')}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Closure.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'closure.title', href: show() },
    ],
};

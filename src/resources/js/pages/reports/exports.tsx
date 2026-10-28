import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import ReportExportController from '@/actions/App/Http/Controllers/Reports/ReportExportController';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { useReportLabel } from '@/components/reports/labels';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { ReportExportRow } from '@/types/reports';

type Props = {
    exports: ReportExportRow[];
    reports: string[];
    retention_hours: number;
    defaults: { from: string; to: string };
};

const POLL_MS = 5000;

/** ReportExport (SHR-15): request a CSV in the background, follow its state, download through a short-lived link. */
export default function ReportExports({
    exports,
    reports,
    retention_hours,
    defaults,
}: Props) {
    const t = useT();
    const label = useReportLabel();
    const { locale } = useLocale();
    const [busy, setBusy] = useState<string | null>(null);
    const [linkError, setLinkError] = useState(false);
    const form = useForm({
        report: reports[0] ?? '',
        from: defaults.from,
        to: defaults.to,
    });
    const pending = exports.some((e) => e.status === 'queued');

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['exports'] }),
            POLL_MS,
        );

        return () => window.clearInterval(timer);
    }, [pending]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(ReportExportController.store.url(), {
            preserveScroll: true,
            onSuccess: () => toast.success(t('reports.exports.requested')),
        });
    };

    const download = async (row: ReportExportRow) => {
        setBusy(row.id);
        setLinkError(false);
        const result = await api<{ url: string; expires_in: number }>(
            'POST',
            ReportExportController.link.url(row.id),
        );
        setBusy(null);

        if (result.ok && result.data) {
            window.location.assign(result.data.url);

            return;
        }

        setLinkError(true);
        router.reload({ only: ['exports'] });
    };

    const state = (row: ReportExportRow) => {
        if (row.status === 'queued') {
            return <Badge variant="info">{t('reports.exports.queued')}</Badge>;
        }

        if (row.status === 'failed') {
            return (
                <div className="flex flex-col gap-1">
                    <Badge variant="danger">
                        {t('reports.exports.failed')}
                    </Badge>
                    <span className="text-caption text-weak">
                        {row.error === 'access'
                            ? t('reports.exports.fail_access')
                            : t('reports.exports.fail_error')}
                    </span>
                </div>
            );
        }

        if (row.expired) {
            return (
                <Badge variant="neutral">{t('reports.exports.expired')}</Badge>
            );
        }

        return (
            <div className="flex flex-col gap-1">
                <Badge variant="success">{t('reports.exports.ready')}</Badge>
                {row.truncated && (
                    <span className="text-caption text-weak">
                        {t('reports.exports.truncated')}
                    </span>
                )}
            </div>
        );
    };

    return (
        <>
            <Head title={t('reports.title.exports')} />
            <PageHeader
                title={t('reports.title.exports')}
                description={t('reports.desc.exports')}
                back={
                    <Link
                        className="text-link"
                        href={ReportsController.index.url()}
                    >
                        {t('reports.back')}
                    </Link>
                }
            />
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle>{t('reports.exports.title')}</CardTitle>
                </CardHeader>
                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4"
                    data-test="export-form"
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <SelectField
                            label={t('reports.exports.report')}
                            value={form.data.report}
                            error={form.errors.report}
                            onChange={(e) =>
                                form.setData('report', e.target.value)
                            }
                            options={reports.map((key) => ({
                                value: key,
                                label: label('title', key),
                            }))}
                        />
                        <TextField
                            type="date"
                            label={t('reports.filters.from')}
                            value={form.data.from}
                            error={form.errors.from}
                            max={form.data.to || undefined}
                            onChange={(e) =>
                                form.setData('from', e.target.value)
                            }
                        />
                        <TextField
                            type="date"
                            label={t('reports.filters.to')}
                            value={form.data.to}
                            error={form.errors.to}
                            min={form.data.from || undefined}
                            onChange={(e) => form.setData('to', e.target.value)}
                        />
                    </div>
                    <p className="text-caption text-weak">
                        {t('reports.exports.scope_note')}
                    </p>
                    <div>
                        <Button
                            type="submit"
                            disabled={form.processing || reports.length === 0}
                            className="max-sm:w-full"
                        >
                            {t('reports.exports.request')}
                        </Button>
                    </div>
                </form>
            </Card>
            {linkError && (
                <Alert className="mb-4" variant="danger">
                    <AlertBody>{t('reports.exports.link_expired')}</AlertBody>
                </Alert>
            )}
            <Card>
                <CardHeader>
                    <CardTitle>{t('reports.exports.history')}</CardTitle>
                    <p className="text-caption text-weak">
                        {t('reports.exports.retention', {
                            hours: retention_hours,
                        })}
                    </p>
                </CardHeader>
                {exports.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title={t('reports.exports.none')}
                        compact
                    />
                ) : (
                    <TableWrap>
                        <Table
                            caption={t('reports.exports.history')}
                            data-test="export-history"
                        >
                            <thead>
                                <tr>
                                    <Th>{t('reports.exports.report')}</Th>
                                    <Th>{t('reports.filters.range')}</Th>
                                    <Th>{t('reports.exports.rows')}</Th>
                                    <Th>{t('reports.exports.status')}</Th>
                                    <Th>
                                        <span className="sr-only">
                                            {t('reports.exports.download')}
                                        </span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {exports.map((row) => (
                                    <tr key={row.id}>
                                        <Td>{label('title', row.report)}</Td>
                                        <Td>
                                            <bdi dir="ltr">
                                                {row.params.from}
                                                {' → '}
                                                {row.params.to}
                                            </bdi>
                                        </Td>
                                        <Td className="tabular-nums">
                                            {row.row_count === null ? (
                                                '—'
                                            ) : (
                                                <bdi dir="ltr">
                                                    {formatNumber(
                                                        row.row_count,
                                                    )}
                                                </bdi>
                                            )}
                                        </Td>
                                        <Td>
                                            <div className="flex flex-col gap-1">
                                                {state(row)}
                                                {row.requested_at && (
                                                    <span className="text-caption text-weak">
                                                        {formatDateTime(
                                                            row.requested_at,
                                                            locale,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                        </Td>
                                        <Td className="text-end">
                                            {row.downloadable && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={busy === row.id}
                                                    onClick={() =>
                                                        void download(row)
                                                    }
                                                >
                                                    <Download aria-hidden="true" />
                                                    {t(
                                                        'reports.exports.download',
                                                    )}
                                                </Button>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>
        </>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import CollectionReportController from '@/actions/App/Http/Controllers/Finance/CollectionReportController';
import FinanceHomeController from '@/actions/App/Http/Controllers/Finance/FinanceHomeController';
import OrderFinanceController from '@/actions/App/Http/Controllers/Finance/OrderFinanceController';
import { toMajor } from '@/components/agreement/money';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { TrendingUp } from 'lucide-react';

type Bucket = { amount_minor: number; count: number };

type Props = {
    report: {
        buckets: Record<'not_overdue' | 'd1_7' | 'd8_30' | 'd30_plus', Bucket>;
        overdue: {
            order: { id: number; number: string };
            customer: string;
            due: string | null;
            amount_minor: number;
            age_days: number;
        }[];
        overdue_total_minor: number;
        month: {
            collected_minor: number;
            corrections_minor: number;
            net_minor: number;
        };
    };
    branch_id: number | null;
    branches: { id: number; name: string }[];
};

/** ReportCollection (SHR-12): dues and their ages from confirmed money only; collected this month from the ledger. */
export default function CollectionReport({
    report,
    branch_id,
    branches,
}: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('finance.report.title')} />
            <PageHeader
                title={t('finance.report.title')}
                description={t('finance.report.description')}
                actions={
                    <Button asChild variant="secondary">
                        <Link href={FinanceHomeController.index.url()}>
                            {t('finance.home.title')}
                        </Link>
                    </Button>
                }
            />
            <div className="mb-4 max-w-xs">
                <SelectField
                    label={t('orders.field.branch')}
                    value={branch_id === null ? '' : String(branch_id)}
                    placeholder={t('finance.report.all_branches')}
                    onChange={(e) =>
                        router.get(
                            CollectionReportController.show.url(),
                            e.target.value ? { branch_id: e.target.value } : {},
                            { preserveState: true },
                        )
                    }
                    options={branches.map((b) => ({
                        value: String(b.id),
                        label: b.name,
                    }))}
                />
            </div>
            <div
                className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                data-test="aging"
            >
                {(['not_overdue', 'd1_7', 'd8_30', 'd30_plus'] as const).map(
                    (b) => (
                        <div
                            key={b}
                            className="flex min-w-0 flex-col gap-1 rounded-lg border border-stroke-weak bg-surface p-5"
                        >
                            <span className="text-label text-weak">
                                {t(
                                    `finance.report.bucket.${b}` as TranslationKey,
                                )}
                            </span>
                            <p className="m-0 text-title text-strong">
                                <Amount
                                    value={toMajor(
                                        report.buckets[b].amount_minor,
                                    )}
                                />
                            </p>
                            <p className="m-0 text-caption text-weak">
                                {t('finance.report.dues_count', {
                                    count: report.buckets[b].count,
                                })}
                            </p>
                        </div>
                    ),
                )}
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{t('finance.report.overdue')}</CardTitle>
                        <p className="text-weak">
                            {t('finance.report.overdue_total')}{' '}
                            <Amount
                                value={toMajor(report.overdue_total_minor)}
                                strong
                            />
                        </p>
                    </CardHeader>
                    {report.overdue.length === 0 ? (
                        <EmptyState
                            icon={TrendingUp}
                            title={t('finance.report.no_overdue')}
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table
                                className="w-full text-body"
                                data-test="overdue-table"
                            >
                                <thead className="text-label text-weak">
                                    <tr>
                                        <th className="py-2 text-start">
                                            {t('finance.home.col.order')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('finance.home.col.customer')}
                                        </th>
                                        <th className="py-2 text-start">
                                            {t('finance.schedule.due')}
                                        </th>
                                        <th className="py-2 text-end">
                                            {t('finance.field.amount')}
                                        </th>
                                        <th className="py-2 text-end">
                                            {t('finance.home.col.age')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.overdue.map((row, i) => (
                                        <tr
                                            key={`${row.order.id}-${i}`}
                                            className="border-t border-stroke-weak"
                                        >
                                            <td className="py-2">
                                                <Link
                                                    className="text-link"
                                                    href={OrderFinanceController.show.url(
                                                        row.order.id,
                                                    )}
                                                >
                                                    <bdi dir="ltr">
                                                        {row.order.number}
                                                    </bdi>
                                                </Link>
                                            </td>
                                            <td className="py-2">
                                                {row.customer}
                                            </td>
                                            <td className="py-2">{row.due}</td>
                                            <td className="py-2 text-end">
                                                <Amount
                                                    value={toMajor(
                                                        row.amount_minor,
                                                    )}
                                                />
                                            </td>
                                            <td className="py-2 text-end">
                                                {t(
                                                    'finance.home.overdue_days',
                                                    { days: row.age_days },
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('finance.report.month')}</CardTitle>
                    </CardHeader>
                    <dl className="grid gap-2" data-test="month">
                        <div className="flex justify-between gap-2">
                            <dt className="text-weak">
                                {t('finance.report.collected')}
                            </dt>
                            <dd>
                                <Amount
                                    value={toMajor(
                                        report.month.collected_minor,
                                    )}
                                />
                            </dd>
                        </div>
                        <div className="flex justify-between gap-2">
                            <dt className="text-weak">
                                {t('finance.report.corrections')}
                            </dt>
                            <dd>
                                <Amount
                                    value={toMajor(
                                        report.month.corrections_minor,
                                    )}
                                />
                            </dd>
                        </div>
                        <div className="flex justify-between gap-2 border-t border-stroke-weak pt-2">
                            <dt className="font-semibold">
                                {t('finance.report.net')}
                            </dt>
                            <dd>
                                <Amount
                                    value={toMajor(report.month.net_minor)}
                                    strong
                                />
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </>
    );
}

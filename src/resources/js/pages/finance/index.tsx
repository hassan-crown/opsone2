import { Head, Link } from '@inertiajs/react';
import { BellRing, Receipt, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import CollectionReportController from '@/actions/App/Http/Controllers/Finance/CollectionReportController';
import FinanceHomeController from '@/actions/App/Http/Controllers/Finance/FinanceHomeController';
import OrderFinanceController from '@/actions/App/Http/Controllers/Finance/OrderFinanceController';
import { toMajor } from '@/components/agreement/money';
import { Shell } from '@/components/finance/dialogs';
import type { ReminderRecipient, WorkItem } from '@/components/finance/types';
import { useFinanceCall } from '@/components/finance/use-finance-call';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Kind = WorkItem['kind'];

type Props = {
    items: WorkItem[];
    counts: Record<Kind, number>;
    can_report: boolean;
};

const TONE: Record<Kind, 'danger' | 'warning' | 'info' | 'neutral'> = {
    overdue: 'danger',
    due: 'warning',
    proof: 'info',
    settlement: 'neutral',
};

/**
 * FinHome (FIN-11): what is due, overdue, waiting for a proof decision or holding unallocated money, oldest first,
 * only for orders whose money the viewer may see. Selected dues can be reminded after a preview (FinReminder).
 */
export default function FinanceHome({ items, counts, can_report }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [filter, setFilter] = useState<Kind | 'all'>('all');
    const [selected, setSelected] = useState<number[]>([]);
    const [preview, setPreview] = useState<ReminderRecipient[] | null>(null);
    const previewing = useFinanceCall<{ recipients: ReminderRecipient[] }>();
    const sending = useFinanceCall<{ sent: number }>();
    const shown =
        filter === 'all' ? items : items.filter((i) => i.kind === filter);

    const toggle = (id: number) =>
        setSelected(
            selected.includes(id)
                ? selected.filter((s) => s !== id)
                : [...selected, id],
        );
    const openPreview = async () => {
        const data = await previewing.run(
            'POST',
            FinanceHomeController.previewReminders.url(),
            { due_ids: selected },
        );
        if (data) {
            setPreview(data.recipients);
        }
    };
    const send = async () => {
        const data = await sending.run(
            'POST',
            FinanceHomeController.sendReminders.url(),
            { due_ids: selected },
        );
        if (data) {
            setPreview(null);
            setSelected([]);
            notify('success', {
                title: t('finance.reminder.sent', { count: data.sent }),
            });
        }
    };

    return (
        <>
            <Head title={t('finance.home.title')} />
            <PageHeader
                title={t('finance.home.title')}
                description={t('finance.home.description')}
                actions={
                    <>
                        {can_report && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={CollectionReportController.show.url()}
                                >
                                    <Icon icon={TrendingUp} />
                                    {t('finance.report.title')}
                                </Link>
                            </Button>
                        )}
                        <Button
                            type="button"
                            onClick={openPreview}
                            disabled={selected.length === 0}
                            loading={previewing.busy}
                            data-test="remind"
                        >
                            <Icon icon={BellRing} />
                            {t('finance.reminder.action')}
                        </Button>
                    </>
                }
            />
            <div
                className="mb-4 flex flex-wrap gap-2"
                role="tablist"
                data-test="work-filters"
            >
                {(
                    ['all', 'overdue', 'due', 'proof', 'settlement'] as const
                ).map((k) => (
                    <Button
                        key={k}
                        type="button"
                        role="tab"
                        aria-selected={filter === k}
                        variant={filter === k ? 'default' : 'secondary'}
                        size="sm"
                        onClick={() => setFilter(k)}
                    >
                        {t(`finance.home.filter.${k}` as TranslationKey)}
                        {k !== 'all' && (
                            <span className="tabular-nums"> ({counts[k]})</span>
                        )}
                    </Button>
                ))}
            </div>
            {previewing.error && (
                <p className="mb-4 text-danger-text">{previewing.error}</p>
            )}
            <Card>
                {shown.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title={t('finance.home.empty')}
                        description={t('finance.home.empty_body')}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table
                            className="w-full text-body"
                            data-test="work-list"
                        >
                            <thead className="text-label text-weak">
                                <tr>
                                    <th className="py-2" />
                                    <th className="py-2 text-start">
                                        {t('finance.home.col.order')}
                                    </th>
                                    <th className="py-2 text-start">
                                        {t('finance.home.col.customer')}
                                    </th>
                                    <th className="py-2 text-start">
                                        {t('finance.home.col.item')}
                                    </th>
                                    <th className="py-2 text-start">
                                        {t('finance.home.col.age')}
                                    </th>
                                    <th className="py-2 text-end">
                                        {t('finance.field.amount')}
                                    </th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((item) => (
                                    <tr
                                        key={item.key}
                                        className="border-t border-stroke-weak"
                                        data-test="work-item"
                                    >
                                        <td className="py-2">
                                            {item.due_id !== undefined && (
                                                <input
                                                    type="checkbox"
                                                    aria-label={t(
                                                        'finance.reminder.select',
                                                    )}
                                                    checked={selected.includes(
                                                        item.due_id,
                                                    )}
                                                    onChange={() =>
                                                        toggle(
                                                            item.due_id as number,
                                                        )
                                                    }
                                                />
                                            )}
                                        </td>
                                        <td className="py-2 font-semibold">
                                            <bdi dir="ltr">
                                                {item.order.number}
                                            </bdi>
                                        </td>
                                        <td className="py-2">
                                            {item.order.customer}
                                        </td>
                                        <td className="py-2">
                                            <Badge variant={TONE[item.kind]}>
                                                {t(
                                                    `finance.home.kind.${item.kind}` as TranslationKey,
                                                )}
                                            </Badge>{' '}
                                            {item.label && (
                                                <span className="text-weak">
                                                    {item.label}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-weak">
                                            {item.kind === 'overdue'
                                                ? t(
                                                      'finance.home.overdue_days',
                                                      { days: item.age_days },
                                                  )
                                                : formatDate(item.date, locale)}
                                        </td>
                                        <td className="py-2 text-end">
                                            <Amount
                                                value={toMajor(
                                                    item.amount_minor,
                                                )}
                                                strong
                                            />
                                        </td>
                                        <td className="py-2 text-end">
                                            <Button
                                                asChild
                                                variant="secondary"
                                                size="sm"
                                            >
                                                <Link
                                                    href={OrderFinanceController.show.url(
                                                        item.order.id,
                                                    )}
                                                >
                                                    {item.kind === 'proof'
                                                        ? t(
                                                              'finance.review.action',
                                                          )
                                                        : t(
                                                              'finance.home.open',
                                                          )}
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            {preview && (
                <Shell
                    test="reminder-preview"
                    title={t('finance.reminder.title', {
                        count: preview.filter((r) => !r.excluded).length,
                    })}
                    description={t('finance.reminder.description')}
                    error={sending.error}
                    onClose={() => setPreview(null)}
                    footer={
                        <Button
                            type="button"
                            onClick={send}
                            loading={sending.busy}
                            disabled={preview.every((r) => r.excluded)}
                            data-test="send-reminders"
                        >
                            {t('finance.reminder.send')}
                        </Button>
                    }
                >
                    <ul className="grid gap-2">
                        {preview.map((r) => (
                            <li
                                key={r.due_id}
                                className="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span>
                                    {r.customer} ·{' '}
                                    <bdi dir="ltr">{r.order.number}</bdi>
                                </span>
                                {r.excluded ? (
                                    <Badge variant="neutral">
                                        {t(
                                            `finance.reminder.excluded.${r.excluded}` as TranslationKey,
                                        )}
                                    </Badge>
                                ) : (
                                    <span className="flex items-center gap-2">
                                        <Amount
                                            value={toMajor(r.amount_minor)}
                                        />
                                        <span className="text-caption text-weak">
                                            {r.channels
                                                .map((c) =>
                                                    t(
                                                        `finance.channel.${c}` as TranslationKey,
                                                    ),
                                                )
                                                .join(' + ')}
                                        </span>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </Shell>
            )}
        </>
    );
}

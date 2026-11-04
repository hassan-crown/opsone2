import { Head, Link, router } from '@inertiajs/react';
import { BellRing, FileText, Receipt, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import CollectionReportController from '@/actions/App/Http/Controllers/Finance/CollectionReportController';
import FinanceHomeController from '@/actions/App/Http/Controllers/Finance/FinanceHomeController';
import OrderFinanceController from '@/actions/App/Http/Controllers/Finance/OrderFinanceController';
import ExternalDocumentController from '@/actions/App/Http/Controllers/Settlement/ExternalDocumentController';
import { toMajor } from '@/components/agreement/money';
import { Pager } from '@/components/customers/pager';
import type { Meta } from '@/components/customers/types';
import { Shell } from '@/components/finance/dialogs';
import type { ReminderRecipient, WorkItem } from '@/components/finance/types';
import { useFinanceCall } from '@/components/finance/use-finance-call';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { FilterChips } from '@/components/opsone/filter-bar';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Ref } from '@/components/opsone/ref';
import { notify } from '@/components/opsone/toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Kind = WorkItem['kind'];

type Props = {
    items: WorkItem[];
    counts: Record<Kind, number>;
    kind: Kind | 'all';
    meta: Meta;
    can_report: boolean;
    can_invoices?: boolean;
};

/** «All» leaves out unallocated money: it has its own tab, last, so overdue rows are not buried under it. */
const KINDS = [
    'all',
    'overdue',
    'due',
    'proof',
    'refund',
    'dispute',
    'settlement',
] as const;

const TONE: Record<Kind, 'danger' | 'warning' | 'info' | 'neutral'> = {
    overdue: 'danger',
    due: 'warning',
    proof: 'info',
    settlement: 'neutral',
    refund: 'warning',
    dispute: 'danger',
};

/**
 * FinHome (FIN-11): what is due, overdue, waiting for a proof decision or holding unallocated money, oldest first,
 * only for orders whose money the viewer may see. Selected dues can be reminded after a preview (FinReminder).
 */
export default function FinanceHome({
    items,
    counts,
    kind,
    meta,
    can_report,
    can_invoices,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [selected, setSelected] = useState<number[]>([]);
    const [preview, setPreview] = useState<ReminderRecipient[] | null>(null);
    const previewing = useFinanceCall<{ recipients: ReminderRecipient[] }>();
    const sending = useFinanceCall<{ sent: number }>();
    const allCount = KINDS.reduce(
        (sum, k) => (k === 'all' || k === 'settlement' ? sum : sum + counts[k]),
        0,
    );
    const showKind = (k: Kind | 'all') =>
        router.get(
            FinanceHomeController.index.url(),
            k === 'all' ? {} : { kind: k },
            { preserveScroll: true, preserveState: true },
        );
    const age = (item: WorkItem) =>
        item.kind === 'overdue'
            ? t('finance.home.overdue_days', { days: item.age_days })
            : formatDate(item.date, locale);

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
                        {can_invoices && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={ExternalDocumentController.index.url()}
                                    data-test="invoices-link"
                                >
                                    <Icon icon={FileText} />
                                    {t('invoices.title')}
                                </Link>
                            </Button>
                        )}
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
            <FilterChips
                className="mb-4"
                label={t('finance.home.filter.label')}
                value={kind}
                onChange={(k) => showKind(k as Kind | 'all')}
                options={KINDS.map((k) => ({
                    value: k,
                    label: t(`finance.home.filter.${k}` as TranslationKey),
                    count: k === 'all' ? allCount : counts[k],
                }))}
            />
            {previewing.error && (
                <p className="mb-4 text-danger-text">{previewing.error}</p>
            )}
            <Card className="gap-0 overflow-hidden p-0 md:p-0">
                {items.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title={t('finance.home.empty')}
                        description={t('finance.home.empty_body')}
                    />
                ) : (
                    <>
                        <ul
                            className="divide-y divide-stroke-weak md:hidden"
                            data-test="work-cards"
                        >
                            {items.map((item) => (
                                <li
                                    key={item.key}
                                    className="flex gap-1 py-3 ps-1 pe-4"
                                    data-test="work-item"
                                >
                                    <div className="w-11 shrink-0">
                                        {item.due_id !== undefined && (
                                            <SelectBox
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
                                    </div>
                                    <div className="grid min-w-0 flex-1 grid-cols-1 gap-1.5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <Ref className="font-semibold">
                                                    {item.order.number}
                                                </Ref>
                                                <p className="line-clamp-2 text-weak">
                                                    {item.order.customer}
                                                </p>
                                            </div>
                                            <span className="shrink-0">
                                                <Amount
                                                    value={toMajor(
                                                        item.amount_minor,
                                                    )}
                                                    strong
                                                />
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-weak">
                                            <KindBadge item={item} />
                                            {item.label && (
                                                <span className="min-w-0 truncate">
                                                    {item.label}
                                                </span>
                                            )}
                                            <span>·</span>
                                            <span>{age(item)}</span>
                                        </div>
                                        <div>
                                            <OpenLink item={item} />
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <TableWrap className="hidden md:block">
                            <Table
                                caption={t('finance.home.title')}
                                data-test="work-list"
                            >
                                <thead>
                                    <tr>
                                        <Th className="w-11 px-2">
                                            <span className="sr-only">
                                                {t('finance.reminder.select')}
                                            </span>
                                        </Th>
                                        <Th>{t('finance.home.col.order')}</Th>
                                        <Th>
                                            {t('finance.home.col.customer')}
                                        </Th>
                                        <Th>{t('finance.home.col.item')}</Th>
                                        <Th>{t('finance.home.col.age')}</Th>
                                        <Th className="text-end">
                                            {t('finance.field.amount')}
                                        </Th>
                                        <Th>
                                            <span className="sr-only">
                                                {t('finance.home.open')}
                                            </span>
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((item) => (
                                        <tr
                                            key={item.key}
                                            data-test="work-item"
                                        >
                                            <Td className="px-2 py-1">
                                                {item.due_id !== undefined && (
                                                    <SelectBox
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
                                            </Td>
                                            <Td>
                                                <Ref className="font-semibold">
                                                    {item.order.number}
                                                </Ref>
                                            </Td>
                                            <Td className="max-w-56">
                                                <span className="line-clamp-2">
                                                    {item.order.customer}
                                                </span>
                                            </Td>
                                            <Td>
                                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <KindBadge item={item} />
                                                    {item.label && (
                                                        <span className="text-weak">
                                                            {item.label}
                                                        </span>
                                                    )}
                                                </div>
                                            </Td>
                                            <Td className="whitespace-nowrap text-weak">
                                                {age(item)}
                                            </Td>
                                            <Td className="text-end">
                                                <Amount
                                                    value={toMajor(
                                                        item.amount_minor,
                                                    )}
                                                    strong
                                                />
                                            </Td>
                                            <Td className="text-end">
                                                <OpenLink item={item} />
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                        <Pager
                            meta={meta}
                            url={FinanceHomeController.index.url()}
                            query={kind === 'all' ? {} : { kind }}
                        />
                    </>
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

function KindBadge({ item }: { item: WorkItem }) {
    const t = useT();

    return (
        <Badge variant={TONE[item.kind]}>
            {t(`finance.home.kind.${item.kind}` as TranslationKey)}
        </Badge>
    );
}

function OpenLink({ item }: { item: WorkItem }) {
    const t = useT();

    return (
        <Button asChild variant="secondary" size="sm">
            <Link href={OrderFinanceController.show.url(item.order.id)}>
                {item.kind === 'proof'
                    ? t('finance.review.action')
                    : t('finance.home.open')}
            </Link>
        </Button>
    );
}

/** A 44px touch target around the native checkbox (the box itself stays 20px). */
function SelectBox({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: () => void;
}) {
    const t = useT();

    return (
        <label className="grid min-h-11 min-w-11 cursor-pointer place-items-center">
            <input
                type="checkbox"
                className="size-5 accent-brand"
                aria-label={t('finance.reminder.select')}
                checked={checked}
                onChange={onChange}
            />
        </label>
    );
}

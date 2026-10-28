import { Head, Link, useForm } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import ReturnController from '@/actions/App/Http/Controllers/Returns/ReturnController';
import { FilterBar, Pager } from '@/components/catalog/shared';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { ReturnStatusBadge } from '@/components/returns/shared';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    EligibleOrder,
    Page,
    ReturnRow,
    ReturnStatus,
} from '@/types/returns';

type Props = {
    eligible: EligibleOrder[];
    returns: Page<ReturnRow>;
    filters: { status: ReturnStatus | null; q: string };
    settings: { window_days: number; can_edit: boolean };
};

const STATUSES: ReturnStatus[] = [
    'requested',
    'accepted',
    'received',
    'inspected',
    'rejected',
    'cancelled',
];

/** ReturnList: delivered ready orders that may return goods, the returns, and the return window (P-02). */
export default function ReturnsIndex({
    eligible,
    returns,
    filters,
    settings,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const filtered = Boolean(filters.q || filters.status);
    const amounts = returns.data.some((r) => r.settlement_minor !== undefined);
    const windowForm = useForm({ window_days: String(settings.window_days) });

    return (
        <>
            <Head title={t('returns.title')} />
            <PageHeader
                title={t('returns.title')}
                description={t('returns.description')}
            />

            <Card className="mb-4 gap-0 p-0 md:p-0" data-test="eligible">
                <h2 className="text-heading px-4 pt-4 text-strong">
                    {t('returns.eligible')}
                </h2>
                {eligible.length === 0 ? (
                    <EmptyState
                        icon={Undo2}
                        title={t('returns.eligible_empty')}
                    />
                ) : (
                    <TableWrap>
                        <Table caption={t('returns.eligible')}>
                            <thead>
                                <tr>
                                    <Th>{t('returns.col_order')}</Th>
                                    <Th>{t('returns.col_customer')}</Th>
                                    <Th>{t('returns.col_lines')}</Th>
                                    <Th>
                                        <span className="sr-only">
                                            {t('returns.col_actions')}
                                        </span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {eligible.map((o) => (
                                    <tr key={o.id} data-test="eligible-row">
                                        <Td className="font-semibold">
                                            <bdi dir="ltr">{o.number}</bdi>
                                        </Td>
                                        <Td>{o.customer}</Td>
                                        <Td>{o.lines}</Td>
                                        <Td>
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="secondary"
                                            >
                                                <Link
                                                    href={ReturnController.create.url(
                                                        o.id,
                                                    )}
                                                >
                                                    {t('returns.new')}
                                                </Link>
                                            </Button>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>

            <h2 className="text-heading mb-2 text-strong">
                {t('returns.list')}
            </h2>
            <FilterBar url={ReturnController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('returns.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-48"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-status">{t('returns.col_status')}</Label>
                    <select
                        id="f-status"
                        name="status"
                        defaultValue={filters.status ?? ''}
                        className="field-control w-48 text-control"
                    >
                        <option value="">{t('catalog.all')}</option>
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`returns.status.${s}` as TranslationKey)}
                            </option>
                        ))}
                    </select>
                </div>
            </FilterBar>
            {returns.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Undo2}
                        title={t(
                            filtered ? 'returns.no_results' : 'returns.empty',
                        )}
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('returns.list')}>
                            <thead>
                                <tr>
                                    <Th>{t('returns.col_number')}</Th>
                                    <Th>{t('returns.col_order')}</Th>
                                    <Th>{t('returns.col_customer')}</Th>
                                    <Th>{t('returns.col_date')}</Th>
                                    <Th>{t('returns.col_status')}</Th>
                                    {amounts && (
                                        <Th className="text-end">
                                            {t('returns.col_settlement')}
                                        </Th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {returns.data.map((r) => (
                                    <tr key={r.id} data-test="return-row">
                                        <Td className="font-semibold whitespace-nowrap">
                                            <Link
                                                href={ReturnController.show.url(
                                                    r.id,
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                <bdi dir="ltr">{r.number}</bdi>
                                            </Link>
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            <bdi dir="ltr">
                                                {r.order.number}
                                            </bdi>
                                        </Td>
                                        <Td>{r.customer}</Td>
                                        <Td>
                                            {formatDate(r.created_at, locale)}
                                        </Td>
                                        <Td>
                                            <ReturnStatusBadge
                                                status={r.status}
                                            />
                                        </Td>
                                        {amounts && (
                                            <Td className="text-end">
                                                {r.settlement_minor !==
                                                undefined ? (
                                                    <Amount
                                                        value={
                                                            r.settlement_minor /
                                                            100
                                                        }
                                                    />
                                                ) : (
                                                    '—'
                                                )}
                                            </Td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <Pager
                page={returns}
                url={ReturnController.index.url()}
                query={{ q: filters.q, status: filters.status }}
            />

            {settings.can_edit && (
                <Card className="mt-4 gap-3" data-test="return-window">
                    <h2 className="text-heading text-strong">
                        {t('returns.settings_title')}
                    </h2>
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            windowForm.put(ReturnController.settings.url(), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <TextField
                            label={t('returns.window_days')}
                            hint={t('returns.window_hint')}
                            type="number"
                            min={0}
                            max={365}
                            dir="ltr"
                            className="w-48"
                            value={windowForm.data.window_days}
                            onChange={(e) =>
                                windowForm.setData(
                                    'window_days',
                                    e.target.value,
                                )
                            }
                            error={windowForm.errors.window_days}
                        />
                        <Button
                            type="submit"
                            variant="secondary"
                            loading={windowForm.processing}
                            disabled={windowForm.processing}
                        >
                            {t('returns.save')}
                        </Button>
                    </form>
                </Card>
            )}
        </>
    );
}
